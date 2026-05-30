<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Contact;

use HiEvents\DomainObjects\Enums\AttendeeContactResolutionAction;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Contact\AttendeeContactLinkResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The single primitive that reconciles attendees.contact_id after an email edit.
 * Covers contactless linking, sole-owner rename vs flag-for-review, the shared
 * (sponsor) split, and the sponsor same-person rename — none of which ever
 * rewrite a sibling attendee's email.
 */
class AttendeeContactLinkResolverTest extends TestCase
{
    use DatabaseTransactions;

    private AttendeeContactLinkResolver $resolver;

    private int $accountId;

    private int $userId;

    private int $eventId;

    private int $productId;

    private int $productPriceId;

    private int $orderId;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(AttendeeContactLinkResolver::class);
        $this->seedFixture();
    }

    public function test_contactless_attendee_is_linked_to_found_or_created_contact(): void
    {
        $attendee = $this->insertAttendee('guest@example.com', null);

        $result = $this->resolver->resolveAfterEmailChange(
            attendeeId: $attendee,
            accountId: $this->accountId,
            previousContactId: null,
            newEmail: 'guest@example.com',
            firstName: 'Guest',
            lastName: 'One',
        );

        $this->assertSame(AttendeeContactResolutionAction::LINKED, $result->action);
        $this->assertTrue($result->linkChanged);
        $contactId = (int) DB::table('attendees')->where('id', $attendee)->value('contact_id');
        $this->assertNotNull($contactId);
        $this->assertSame('guest@example.com', DB::table('contacts')->where('id', $contactId)->value('email'));
    }

    public function test_matching_email_is_unchanged_and_clears_flag(): void
    {
        $contactId = $this->insertContact('same@example.com');
        $attendee = $this->insertAttendee('same@example.com', $contactId, flagged: true);

        $result = $this->resolver->resolveAfterEmailChange(
            attendeeId: $attendee,
            accountId: $this->accountId,
            previousContactId: $contactId,
            newEmail: 'same@example.com',
            firstName: 'A',
            lastName: 'B',
        );

        $this->assertSame(AttendeeContactResolutionAction::UNCHANGED, $result->action);
        $this->assertNull(DB::table('attendees')->where('id', $attendee)->value('contact_email_divergence_flagged_at'));
    }

    public function test_door_sole_owner_divergence_is_flagged_for_review(): void
    {
        $contactId = $this->insertContact('old@example.com');
        $attendee = $this->insertAttendee('new@example.com', $contactId, flagged: false);

        $result = $this->resolver->resolveAfterEmailChange(
            attendeeId: $attendee,
            accountId: $this->accountId,
            previousContactId: $contactId,
            newEmail: 'new@example.com',
            firstName: 'A',
            lastName: 'B',
            renameSoleOwnerContact: false,
        );

        $this->assertSame(AttendeeContactResolutionAction::FLAGGED, $result->action);
        // Contact untouched; row flagged for review.
        $this->assertSame('old@example.com', DB::table('contacts')->where('id', $contactId)->value('email'));
        $this->assertNotNull(DB::table('attendees')->where('id', $attendee)->value('contact_email_divergence_flagged_at'));
        $this->assertSame($contactId, (int) DB::table('attendees')->where('id', $attendee)->value('contact_id'));
    }

    public function test_self_service_sole_owner_renames_contact_in_place(): void
    {
        $contactId = $this->insertContact('old@example.com');
        $attendee = $this->insertAttendee('new@example.com', $contactId);

        $result = $this->resolver->resolveAfterEmailChange(
            attendeeId: $attendee,
            accountId: $this->accountId,
            previousContactId: $contactId,
            newEmail: 'new@example.com',
            firstName: 'A',
            lastName: 'B',
            renameSoleOwnerContact: true,
        );

        $this->assertSame(AttendeeContactResolutionAction::CONTACT_RENAMED, $result->action);
        $this->assertSame('new@example.com', DB::table('contacts')->where('id', $contactId)->value('email'));
    }

    public function test_shared_contact_guest_splits_off_without_touching_siblings(): void
    {
        $contactId = $this->insertContact('sponsor@example.com');
        $guest = $this->insertAttendee('guest@example.com', $contactId);
        $sibling = $this->insertAttendee('sponsor@example.com', $contactId);

        $result = $this->resolver->resolveAfterEmailChange(
            attendeeId: $guest,
            accountId: $this->accountId,
            previousContactId: $contactId,
            newEmail: 'guest@example.com',
            firstName: 'Guest',
            lastName: 'Different',
            buyerFirstName: 'Pat',
            buyerLastName: 'Sponsor',
        );

        $this->assertSame(AttendeeContactResolutionAction::SPLIT, $result->action);
        $this->assertTrue($result->linkChanged);
        // Sponsor contact + sibling untouched.
        $this->assertSame('sponsor@example.com', DB::table('contacts')->where('id', $contactId)->value('email'));
        $this->assertSame($contactId, (int) DB::table('attendees')->where('id', $sibling)->value('contact_id'));
        $this->assertSame('sponsor@example.com', DB::table('attendees')->where('id', $sibling)->value('email'));
        // Guest now on their own contact.
        $guestContact = (int) DB::table('attendees')->where('id', $guest)->value('contact_id');
        $this->assertNotSame($contactId, $guestContact);
    }

    public function test_shared_contact_name_matches_buyer_without_decision_is_flagged(): void
    {
        $contactId = $this->insertContact('sponsor@example.com');
        $sponsor = $this->insertAttendee('sponsor-new@example.com', $contactId, flagged: false);
        $this->insertAttendee('sponsor@example.com', $contactId);

        $result = $this->resolver->resolveAfterEmailChange(
            attendeeId: $sponsor,
            accountId: $this->accountId,
            previousContactId: $contactId,
            newEmail: 'sponsor-new@example.com',
            firstName: 'Pat',
            lastName: 'Sponsor',
            buyerFirstName: 'Pat',
            buyerLastName: 'Sponsor',
            sponsorDecision: null,
        );

        $this->assertSame(AttendeeContactResolutionAction::FLAGGED, $result->action);
        $this->assertSame('sponsor@example.com', DB::table('contacts')->where('id', $contactId)->value('email'));
    }

    public function test_shared_contact_sponsor_same_person_renames_contact(): void
    {
        $contactId = $this->insertContact('sponsor@example.com');
        $sponsor = $this->insertAttendee('sponsor-new@example.com', $contactId);
        $sibling = $this->insertAttendee('sponsor@example.com', $contactId);

        $result = $this->resolver->resolveAfterEmailChange(
            attendeeId: $sponsor,
            accountId: $this->accountId,
            previousContactId: $contactId,
            newEmail: 'sponsor-new@example.com',
            firstName: 'Pat',
            lastName: 'Sponsor',
            buyerFirstName: 'Pat',
            buyerLastName: 'Sponsor',
            sponsorDecision: AttendeeContactLinkResolver::SPONSOR_SAME_PERSON,
        );

        $this->assertSame(AttendeeContactResolutionAction::CONTACT_RENAMED, $result->action);
        // Contact renamed; sibling keeps its historical email and link (no cascade).
        $this->assertSame('sponsor-new@example.com', DB::table('contacts')->where('id', $contactId)->value('email'));
        $this->assertSame('sponsor@example.com', DB::table('attendees')->where('id', $sibling)->value('email'));
        $this->assertSame($contactId, (int) DB::table('attendees')->where('id', $sibling)->value('contact_id'));
    }

    public function test_shared_contact_different_person_splits_even_when_name_matches_buyer(): void
    {
        $contactId = $this->insertContact('sponsor@example.com');
        $seatTaker = $this->insertAttendee('someone@example.com', $contactId);
        $this->insertAttendee('sponsor@example.com', $contactId);

        $result = $this->resolver->resolveAfterEmailChange(
            attendeeId: $seatTaker,
            accountId: $this->accountId,
            previousContactId: $contactId,
            newEmail: 'someone@example.com',
            firstName: 'Pat',
            lastName: 'Sponsor',
            buyerFirstName: 'Pat',
            buyerLastName: 'Sponsor',
            sponsorDecision: AttendeeContactLinkResolver::SPONSOR_DIFFERENT_PERSON,
        );

        $this->assertSame(AttendeeContactResolutionAction::SPLIT, $result->action);
        $this->assertSame('sponsor@example.com', DB::table('contacts')->where('id', $contactId)->value('email'));
    }

    private function seedFixture(): void
    {
        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => ['percentage' => 1.5, 'fixed' => 0],
        ]);

        $user = User::factory()->withAccount()->create();
        $this->userId = (int) $user->id;
        $this->accountId = (int) $user->accounts()->first()->id;

        $this->eventId = (int) DB::table('events')->insertGetId([
            'title' => 'Test Event',
            'account_id' => $this->accountId,
            'user_id' => $this->userId,
            'short_id' => $this->unique('evt'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->productId = (int) DB::table('products')->insertGetId([
            'title' => 'General Admission',
            'event_id' => $this->eventId,
            'order' => 1,
            'created_at' => now(),
        ]);

        $this->productPriceId = (int) DB::table('product_prices')->insertGetId([
            'product_id' => $this->productId,
            'price' => 0,
            'created_at' => now(),
        ]);

        $this->orderId = (int) DB::table('orders')->insertGetId([
            'short_id' => $this->unique('ord'),
            'public_id' => $this->unique('o_'),
            'event_id' => $this->eventId,
            'currency' => 'USD',
            'status' => 'COMPLETED',
            'created_at' => now(),
        ]);
    }

    private function insertContact(string $email): int
    {
        return (int) DB::table('contacts')->insertGetId([
            'account_id' => $this->accountId,
            'email' => $email,
            'first_name' => 'F',
            'last_name' => 'L',
            'attributes' => json_encode([]),
            'attributes_history' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAttendee(string $email, ?int $contactId, bool $flagged = false): int
    {
        return (int) DB::table('attendees')->insertGetId([
            'short_id' => $this->unique('att'),
            'public_id' => $this->unique('a_'),
            'order_id' => $this->orderId,
            'product_id' => $this->productId,
            'product_price_id' => $this->productPriceId,
            'event_id' => $this->eventId,
            'contact_id' => $contactId,
            'status' => 'ACTIVE',
            'first_name' => 'A',
            'last_name' => 'B',
            'email' => $email,
            'contact_email_divergence_flagged_at' => $flagged ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function unique(string $prefix): string
    {
        return $prefix.'_'.$this->accountId.'_'.(++$this->seq);
    }
}
