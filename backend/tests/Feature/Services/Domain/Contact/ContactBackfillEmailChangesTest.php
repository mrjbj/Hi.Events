<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Contact;

use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Contact\ContactBackfillService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sync → "Email Changes" sub-tab.
 *
 * Detection is EDIT-DRIVEN: a row only appears when its email was deliberately
 * edited so it diverges from its linked contact (the door / self-service edit
 * sets contact_email_divergence_flagged_at). A contact-side email change never
 * sets that flag, so attendees left behind by a contact rename stay out of the
 * queue — their per-event email is historical fact.
 *
 * Decisions never cascade onto sibling attendee rows:
 *   - 'update' renames the contact, but ONLY when it's the attendee's alone;
 *     a shared contact (or a taken address) degrades to a split.
 *   - 'split' re-points just this attendee to its own contact.
 *   - 'ignore' dismisses the row (kept under "show kept").
 */
class ContactBackfillEmailChangesTest extends TestCase
{
    use DatabaseTransactions;

    private ContactBackfillService $service;

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
        $this->service = app(ContactBackfillService::class);
        $this->seedFixture();
    }

    public function test_detects_flagged_attendee_whose_email_diverged_from_contact(): void
    {
        $contactId = $this->insertContact('contact@example.com');
        $this->insertAttendee('changed@example.com', $contactId, flagged: true);
        // Matches its contact → excluded even though flagged.
        $matchingContactId = $this->insertContact('matched@example.com');
        $this->insertAttendee('matched@example.com', $matchingContactId, flagged: true);

        $rows = $this->fetchAll();

        $this->assertCount(1, $rows);
        $this->assertSame('contact@example.com', $rows[0]->contact_email);
        $this->assertSame('changed@example.com', $rows[0]->attendee_email);
        $this->assertFalse($rows[0]->processed);
    }

    public function test_diverged_but_unflagged_attendee_is_not_in_queue(): void
    {
        // An attendee left behind by a contact-side rename: diverges, but was never
        // deliberately edited, so it must NOT surface for review.
        $contactId = $this->insertContact('renamed@example.com');
        $this->insertAttendee('historical@example.com', $contactId, flagged: false);

        $this->assertCount(0, $this->fetchAll());
    }

    public function test_case_only_difference_is_not_a_divergence(): void
    {
        $contactId = $this->insertContact('person@example.com');
        $this->insertAttendee('Person@Example.com', $contactId, flagged: true);

        $this->assertCount(0, $this->fetchAll(), 'Email comparison is case-insensitive.');
    }

    public function test_unlinked_attendee_is_ignored(): void
    {
        $this->insertAttendee('orphan@example.com', null, flagged: true);

        $this->assertCount(0, $this->fetchAll());
    }

    public function test_included_in_summary_count(): void
    {
        $c1 = $this->insertContact('a@example.com');
        $this->insertAttendee('a-new@example.com', $c1, flagged: true);
        $c2 = $this->insertContact('b@example.com');
        $this->insertAttendee('b@example.com', $c2, flagged: true); // matches, not counted

        $summary = $this->service->getSummaryCounts($this->accountId);

        $this->assertSame(1, $summary['email_changes_count']);
    }

    public function test_shared_count_reflects_siblings_on_the_contact(): void
    {
        $contactId = $this->insertContact('sponsor@example.com');
        $edited = $this->insertAttendee('guest@example.com', $contactId, flagged: true);
        $this->insertAttendee('sponsor@example.com', $contactId, flagged: false); // sibling
        $this->insertAttendee('sponsor@example.com', $contactId, flagged: false); // sibling

        $rows = $this->fetchAll();

        $this->assertCount(1, $rows);
        $this->assertSame($edited, (int) $rows[0]->attendee_id);
        $this->assertSame(3, (int) $rows[0]->shared_count);
        $this->assertTrue((bool) $rows[0]->shared);
    }

    public function test_update_on_sole_owner_renames_contact_in_place(): void
    {
        $contactId = $this->insertContact('old@example.com');
        $edited = $this->insertAttendee('new@example.com', $contactId, flagged: true);

        $count = $this->service->applyEmailChangeDecisions(
            $this->accountId,
            [['attendee_id' => $edited, 'decision' => 'update']],
            $this->userId,
        );

        $this->assertSame(1, $count);
        $this->assertSame('new@example.com', DB::table('contacts')->where('id', $contactId)->value('email'));
        $this->assertSame($contactId, (int) DB::table('attendees')->where('id', $edited)->value('contact_id'));
        $this->assertNull(DB::table('attendees')->where('id', $edited)->value('contact_email_divergence_flagged_at'));
        $this->assertCount(0, $this->fetchAll());
    }

    public function test_update_on_shared_contact_splits_instead_of_renaming(): void
    {
        $contactId = $this->insertContact('sponsor@example.com');
        $edited = $this->insertAttendee('guest@example.com', $contactId, flagged: true);
        $sibling = $this->insertAttendee('sponsor@example.com', $contactId, flagged: false);

        $this->service->applyEmailChangeDecisions(
            $this->accountId,
            [['attendee_id' => $edited, 'decision' => 'update']],
            $this->userId,
        );

        // Shared → the contact is NOT renamed; the edited attendee splits off.
        $this->assertSame('sponsor@example.com', DB::table('contacts')->where('id', $contactId)->value('email'));
        $this->assertSame('sponsor@example.com', DB::table('attendees')->where('id', $sibling)->value('email'));
        $this->assertSame($contactId, (int) DB::table('attendees')->where('id', $sibling)->value('contact_id'));

        $editedContactId = (int) DB::table('attendees')->where('id', $edited)->value('contact_id');
        $this->assertNotSame($contactId, $editedContactId);
        $this->assertSame('guest@example.com', DB::table('contacts')->where('id', $editedContactId)->value('email'));
        $this->assertCount(0, $this->fetchAll());
    }

    public function test_split_decision_repoints_attendee_to_matched_contact(): void
    {
        // The new address already belongs to an existing contact → link to it.
        $existing = $this->insertContact('real@example.com');
        $contactId = $this->insertContact('sponsor@example.com');
        $edited = $this->insertAttendee('real@example.com', $contactId, flagged: true);
        $this->insertAttendee('sponsor@example.com', $contactId, flagged: false); // makes it shared

        $this->service->applyEmailChangeDecisions(
            $this->accountId,
            [['attendee_id' => $edited, 'decision' => 'split']],
            $this->userId,
        );

        $this->assertSame($existing, (int) DB::table('attendees')->where('id', $edited)->value('contact_id'));
        $this->assertSame('sponsor@example.com', DB::table('contacts')->where('id', $contactId)->value('email'));
    }

    public function test_update_degrades_to_split_when_target_owned_by_another_contact(): void
    {
        $taken = $this->insertContact('taken@example.com');
        $contactId = $this->insertContact('owner@example.com');
        $edited = $this->insertAttendee('taken@example.com', $contactId, flagged: true);

        $this->service->applyEmailChangeDecisions(
            $this->accountId,
            [['attendee_id' => $edited, 'decision' => 'update']],
            $this->userId,
        );

        // Must not steal the address; the attendee links to the existing owner.
        $this->assertSame('owner@example.com', DB::table('contacts')->where('id', $contactId)->value('email'));
        $this->assertSame($taken, (int) DB::table('attendees')->where('id', $edited)->value('contact_id'));
    }

    public function test_ignore_decision_leaves_everything_and_drops_row(): void
    {
        $contactId = $this->insertContact('keep@example.com');
        $attendeeId = $this->insertAttendee('ticketonly@example.com', $contactId, flagged: true);

        $count = $this->service->applyEmailChangeDecisions(
            $this->accountId,
            [['attendee_id' => $attendeeId, 'decision' => 'ignore']],
            $this->userId,
        );

        $this->assertSame(1, $count);
        $this->assertSame('keep@example.com', DB::table('contacts')->where('id', $contactId)->value('email'));
        $this->assertSame('ticketonly@example.com', DB::table('attendees')->where('id', $attendeeId)->value('email'));
        $this->assertNotNull(DB::table('attendees')->where('id', $attendeeId)->value('contact_email_divergence_ignored_at'));

        $this->assertCount(0, $this->fetchAll());
        $processed = $this->fetchAll(includeProcessed: true);
        $this->assertCount(1, $processed);
        $this->assertTrue($processed[0]->processed);
    }

    public function test_decisions_are_scoped_by_account(): void
    {
        $contactId = $this->insertContact('scoped@example.com');
        $attendeeId = $this->insertAttendee('changed@example.com', $contactId, flagged: true);

        $this->service->applyEmailChangeDecisions(
            $this->accountId + 1,
            [['attendee_id' => $attendeeId, 'decision' => 'update']],
            $this->userId,
        );

        $this->assertSame('scoped@example.com', DB::table('contacts')->where('id', $contactId)->value('email'));
    }

    private function fetchAll(bool $includeProcessed = false): array
    {
        return $this->service->getEmailChanges(
            $this->accountId,
            QueryParamsDTO::fromArray(['per_page' => 100, 'page' => 1]),
            includeProcessed: $includeProcessed,
        )->items();
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

    private function insertAttendee(string $email, ?int $contactId, bool $flagged = true): int
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
