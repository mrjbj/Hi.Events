<?php

namespace Tests\Unit\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Enums\AttendeeContactResolutionAction;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Mail\Attendee\AttendeeDetailsChangedMail;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\Public\PatchCheckInListAttendeePublicHandler;
use HiEvents\Services\Domain\Attendee\BundleSeatInfoPropagationService;
use HiEvents\Services\Domain\Contact\AttendeeContactLinkResolver;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use HiEvents\Services\Domain\Contact\DTO\AttendeeContactResolutionDTO;
use Illuminate\Support\Facades\Mail;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class PatchCheckInListAttendeePublicHandlerTest extends TestCase
{
    private CheckInListRepositoryInterface $checkInListRepository;

    private AttendeeRepositoryInterface $attendeeRepository;

    private BundleSeatInfoPropagationService $bundleSeatInfoPropagationService;

    private EventRepositoryInterface $eventRepository;

    private AttendeeContactLinkResolver $contactLinkResolver;

    private ContactSignedTokenService $contactTokenService;

    private LoggerInterface $logger;

    private PatchCheckInListAttendeePublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->checkInListRepository = m::mock(CheckInListRepositoryInterface::class);
        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);
        $this->bundleSeatInfoPropagationService = m::mock(BundleSeatInfoPropagationService::class);
        $this->bundleSeatInfoPropagationService->shouldReceive('propagate')->byDefault();
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->contactLinkResolver = m::mock(AttendeeContactLinkResolver::class);
        $this->contactTokenService = m::mock(ContactSignedTokenService::class);
        $this->logger = m::mock(LoggerInterface::class);
        $this->logger->shouldReceive('warning')->byDefault();

        $this->handler = new PatchCheckInListAttendeePublicHandler(
            $this->attendeeRepository,
            $this->checkInListRepository,
            $this->bundleSeatInfoPropagationService,
            $this->eventRepository,
            $this->contactLinkResolver,
            $this->contactTokenService,
            $this->logger,
        );
    }

    private function activeCheckInList(): CheckInListDomainObject
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->andReturn(null);

        return $checkInList;
    }

    private function resolutionUnchanged(): AttendeeContactResolutionDTO
    {
        return new AttendeeContactResolutionDTO(AttendeeContactResolutionAction::UNCHANGED, null, false);
    }

    public function test_throws_not_found_when_check_in_list_missing(): void
    {
        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturnNull();

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle('short-id', 'attendee-public-id', ['first_name' => 'Alice']);
    }

    public function test_throws_cannot_check_in_when_list_expired(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->twice()->andReturn(now()->subMinute());

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->expectException(CannotCheckInException::class);

        $this->handler->handle('short-id', 'attendee-public-id', ['first_name' => 'Alice']);
    }

    public function test_throws_not_found_when_attendee_not_on_list(): void
    {
        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($this->activeCheckInList());

        $this->attendeeRepository
            ->shouldReceive('findAttendeeOnCheckInList')
            ->once()
            ->with('short-id', 'attendee-public-id')
            ->andReturnNull();

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle('short-id', 'attendee-public-id', ['first_name' => 'Alice']);
    }

    public function test_updates_fields_and_resolves_contact_on_email_change(): void
    {
        $existingAttendee = m::mock(AttendeeDomainObject::class);
        $existingAttendee->shouldReceive('getId')->andReturn(42);
        $existingAttendee->shouldReceive('getSeatInfo')->andReturn(null);
        $existingAttendee->shouldReceive('getConfirmAtCheckin')->andReturn(false);
        $existingAttendee->shouldReceive('getEmail')->andReturn('old@example.com');
        $existingAttendee->shouldReceive('getEventId')->andReturn(789);
        $existingAttendee->shouldReceive('getContactId')->andReturn(7);
        $existingAttendee->shouldReceive('getLastName')->andReturn('Smith');

        $refreshedAttendee = m::mock(AttendeeDomainObject::class);
        $refreshedAttendee->shouldReceive('getId')->andReturn(42);
        // No contact after resolution → no token minting in this case.
        $refreshedAttendee->shouldReceive('getContactId')->andReturn(null);

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(1);

        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->activeCheckInList());
        $this->attendeeRepository->shouldReceive('findAttendeeOnCheckInList')->once()->andReturn($existingAttendee);

        // The re-arm column is gone — only the provided fields are written.
        $this->attendeeRepository
            ->shouldReceive('updateFromArray')
            ->once()
            ->with(42, [
                'first_name' => 'Alice',
                'email' => 'alice@example.com',
            ]);

        $this->eventRepository->shouldReceive('findById')->with(789)->andReturn($event);

        $this->contactLinkResolver
            ->shouldReceive('resolveAfterEmailChange')
            ->once()
            ->withArgs(function (...$args) {
                return $args[0] === 42                       // attendeeId
                    && $args[1] === 1                        // accountId
                    && $args[2] === 7                        // previousContactId
                    && $args[3] === 'alice@example.com';     // newEmail
            })
            ->andReturn($this->resolutionUnchanged());

        $this->attendeeRepository->shouldReceive('findById')->once()->with(42)->andReturn($refreshedAttendee);

        $result = $this->handler->handle('short-id', 'attendee-public-id', [
            'first_name' => 'Alice',
            'email' => 'alice@example.com',
        ]);

        $this->assertSame($refreshedAttendee, $result);
    }

    public function test_mints_contact_token_when_attendee_linked_after_email_change(): void
    {
        $existingAttendee = m::mock(AttendeeDomainObject::class);
        $existingAttendee->shouldReceive('getId')->andReturn(42);
        $existingAttendee->shouldReceive('getSeatInfo')->andReturn(null);
        $existingAttendee->shouldReceive('getEmail')->andReturn('');           // contactless
        $existingAttendee->shouldReceive('getEventId')->andReturn(789);
        $existingAttendee->shouldReceive('getContactId')->andReturn(null);
        $existingAttendee->shouldReceive('getFirstName')->andReturn('Guest');
        $existingAttendee->shouldReceive('getLastName')->andReturn('One');

        $refreshedAttendee = m::mock(AttendeeDomainObject::class);
        $refreshedAttendee->shouldReceive('getId')->andReturn(42);
        $refreshedAttendee->shouldReceive('getContactId')->andReturn(99);
        $refreshedAttendee->shouldReceive('setContactToken')->once()->with('fresh-token');

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(1);

        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->activeCheckInList());
        $this->attendeeRepository->shouldReceive('findAttendeeOnCheckInList')->once()->andReturn($existingAttendee);
        $this->attendeeRepository->shouldReceive('updateFromArray')->once()->with(42, ['email' => 'guest@example.com']);
        $this->eventRepository->shouldReceive('findById')->with(789)->andReturn($event);

        $this->contactLinkResolver
            ->shouldReceive('resolveAfterEmailChange')
            ->once()
            ->andReturn(new AttendeeContactResolutionDTO(AttendeeContactResolutionAction::LINKED, 99, true));

        $this->attendeeRepository->shouldReceive('findById')->once()->with(42)->andReturn($refreshedAttendee);
        $this->contactTokenService->shouldReceive('generate')->once()->with(99, 1)->andReturn('fresh-token');

        $result = $this->handler->handle('short-id', 'attendee-public-id', [
            'email' => 'guest@example.com',
        ]);

        $this->assertSame($refreshedAttendee, $result);
    }

    public function test_contactless_attendee_with_unchanged_email_does_not_link(): void
    {
        // A grouped guest still carrying the buyer's inherited email, saved with
        // that same address: we must NOT link, because that would merge the guest
        // onto the buyer's contact. Separation requires a NEW, distinct email.
        $existingAttendee = m::mock(AttendeeDomainObject::class);
        $existingAttendee->shouldReceive('getId')->andReturn(42);
        $existingAttendee->shouldReceive('getSeatInfo')->andReturn(null);
        $existingAttendee->shouldReceive('getEmail')->andReturn('buyer@example.com');
        $existingAttendee->shouldReceive('getContactId')->andReturn(null);    // contactless

        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->activeCheckInList());
        $this->attendeeRepository->shouldReceive('findAttendeeOnCheckInList')->once()->andReturn($existingAttendee);
        // Same email submitted → no DB write, no resolver, no token.
        $this->attendeeRepository->shouldReceive('updateFromArray')->once()->with(42, ['email' => 'buyer@example.com']);
        $this->contactLinkResolver->shouldNotReceive('resolveAfterEmailChange');
        $this->eventRepository->shouldNotReceive('findById');
        $this->contactTokenService->shouldNotReceive('generate');
        $this->attendeeRepository->shouldReceive('findById')->once()->with(42)->andReturn($existingAttendee);

        $result = $this->handler->handle('short-id', 'attendee-public-id', [
            'email' => 'buyer@example.com',
        ]);

        $this->assertSame($existingAttendee, $result);
        Mail::assertNothingQueued();
    }

    public function test_passes_sponsor_decision_through_to_resolver(): void
    {
        $existingAttendee = m::mock(AttendeeDomainObject::class);
        $existingAttendee->shouldReceive('getId')->andReturn(42);
        $existingAttendee->shouldReceive('getSeatInfo')->andReturn(null);
        $existingAttendee->shouldReceive('getEmail')->andReturn('sponsor@old.com');
        $existingAttendee->shouldReceive('getEventId')->andReturn(789);
        $existingAttendee->shouldReceive('getContactId')->andReturn(7);
        $existingAttendee->shouldReceive('getFirstName')->andReturn('Pat');
        $existingAttendee->shouldReceive('getLastName')->andReturn('Sponsor');

        $refreshedAttendee = m::mock(AttendeeDomainObject::class);
        $refreshedAttendee->shouldReceive('getId')->andReturn(42);
        $refreshedAttendee->shouldReceive('getContactId')->andReturn(null);

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(1);

        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->activeCheckInList());
        $this->attendeeRepository->shouldReceive('findAttendeeOnCheckInList')->once()->andReturn($existingAttendee);
        $this->attendeeRepository->shouldReceive('updateFromArray')->once();
        $this->eventRepository->shouldReceive('findById')->with(789)->andReturn($event);

        $this->contactLinkResolver
            ->shouldReceive('resolveAfterEmailChange')
            ->once()
            ->withArgs(fn (...$args) => ($args[8] ?? null) === AttendeeContactLinkResolver::SPONSOR_SAME_PERSON)
            ->andReturn($this->resolutionUnchanged());

        $this->attendeeRepository->shouldReceive('findById')->once()->with(42)->andReturn($refreshedAttendee);

        $this->handler->handle('short-id', 'attendee-public-id', [
            'email' => 'sponsor@new.com',
            'contact_resolution' => AttendeeContactLinkResolver::SPONSOR_SAME_PERSON,
        ]);
    }

    public function test_skips_resolver_when_email_unchanged(): void
    {
        $existingAttendee = m::mock(AttendeeDomainObject::class);
        $existingAttendee->shouldReceive('getId')->andReturn(42);
        $existingAttendee->shouldReceive('getSeatInfo')->andReturn(null);
        $existingAttendee->shouldReceive('getEmail')->andReturn('old@example.com');

        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->activeCheckInList());
        $this->attendeeRepository->shouldReceive('findAttendeeOnCheckInList')->once()->andReturn($existingAttendee);
        $this->attendeeRepository->shouldReceive('updateFromArray')->once()->with(42, ['confirm_at_checkin' => true]);
        $this->attendeeRepository->shouldReceive('findById')->once()->with(42)->andReturn($existingAttendee);

        // No email change → resolver, eventRepository and token service are untouched.
        $this->contactLinkResolver->shouldNotReceive('resolveAfterEmailChange');
        $this->eventRepository->shouldNotReceive('findById');
        $this->contactTokenService->shouldNotReceive('generate');
        $existingAttendee->shouldReceive('getContactId')->andReturn(7);

        $result = $this->handler->handle('short-id', 'attendee-public-id', [
            'confirm_at_checkin' => true,
        ]);

        $this->assertSame($existingAttendee, $result);
    }

    public function test_skips_update_when_all_fields_blank_but_still_returns_attendee(): void
    {
        $existingAttendee = m::mock(AttendeeDomainObject::class);
        $existingAttendee->shouldReceive('getId')->andReturn(42);
        $existingAttendee->shouldReceive('getSeatInfo')->andReturn(null);
        $existingAttendee->shouldReceive('getEmail')->andReturn('old@example.com');

        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->activeCheckInList());
        $this->attendeeRepository->shouldReceive('findAttendeeOnCheckInList')->once()->andReturn($existingAttendee);
        $this->attendeeRepository->shouldNotReceive('updateFromArray');
        $this->contactLinkResolver->shouldNotReceive('resolveAfterEmailChange');
        $this->attendeeRepository->shouldReceive('findById')->once()->with(42)->andReturn($existingAttendee);
        $existingAttendee->shouldReceive('getContactId')->andReturn(null);

        $result = $this->handler->handle('short-id', 'attendee-public-id', [
            'first_name' => '',
            'last_name' => null,
        ]);

        $this->assertSame($existingAttendee, $result);
    }

    public function test_notifies_old_email_when_email_changed_and_notify_requested(): void
    {
        $existingAttendee = m::mock(AttendeeDomainObject::class);
        $existingAttendee->shouldReceive('getId')->andReturn(42);
        $existingAttendee->shouldReceive('getSeatInfo')->andReturn(null);
        $existingAttendee->shouldReceive('getEmail')->andReturn('old@example.com');
        $existingAttendee->shouldReceive('getEventId')->andReturn(789);
        $existingAttendee->shouldReceive('getContactId')->andReturn(7);
        $existingAttendee->shouldReceive('getFirstName')->andReturn('A');
        $existingAttendee->shouldReceive('getLastName')->andReturn('B');

        $product = m::mock(ProductDomainObject::class);
        $product->shouldReceive('getTitle')->andReturn('General Admission');
        $attendeeWithProduct = m::mock(AttendeeDomainObject::class);
        $attendeeWithProduct->shouldReceive('getProduct')->andReturn($product);

        $eventSettings = m::mock(EventSettingDomainObject::class);
        $eventSettings->shouldReceive('getSupportEmail')->andReturn('support@example.com');
        $organizer = m::mock(OrganizerDomainObject::class);
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(1);
        $event->shouldReceive('getOrganizer')->andReturn($organizer);
        $event->shouldReceive('getEventSettings')->andReturn($eventSettings);

        $refreshedAttendee = m::mock(AttendeeDomainObject::class);
        $refreshedAttendee->shouldReceive('getId')->andReturn(42);
        $refreshedAttendee->shouldReceive('getContactId')->andReturn(null);

        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->activeCheckInList());
        $this->attendeeRepository->shouldReceive('findAttendeeOnCheckInList')->once()->andReturn($existingAttendee);
        $this->attendeeRepository->shouldReceive('updateFromArray')->once()->with(42, ['email' => 'new@example.com']);

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->with(789)->andReturn($event);

        $this->contactLinkResolver->shouldReceive('resolveAfterEmailChange')->once()->andReturn($this->resolutionUnchanged());

        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository->shouldReceive('findById')->with(42)->andReturn($attendeeWithProduct, $refreshedAttendee);

        $result = $this->handler->handle('short-id', 'attendee-public-id', [
            'email' => 'new@example.com',
            'notify_email_change' => true,
        ]);

        $this->assertSame($refreshedAttendee, $result);

        Mail::assertQueued(AttendeeDetailsChangedMail::class, fn ($mail) => $mail->hasTo('old@example.com'));
    }

    public function test_does_not_notify_when_notify_flag_absent(): void
    {
        $existingAttendee = m::mock(AttendeeDomainObject::class);
        $existingAttendee->shouldReceive('getId')->andReturn(42);
        $existingAttendee->shouldReceive('getSeatInfo')->andReturn(null);
        $existingAttendee->shouldReceive('getEmail')->andReturn('old@example.com');
        $existingAttendee->shouldReceive('getEventId')->andReturn(789);
        $existingAttendee->shouldReceive('getContactId')->andReturn(7);
        $existingAttendee->shouldReceive('getFirstName')->andReturn('A');
        $existingAttendee->shouldReceive('getLastName')->andReturn('B');

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(1);

        $refreshedAttendee = m::mock(AttendeeDomainObject::class);
        $refreshedAttendee->shouldReceive('getId')->andReturn(42);
        $refreshedAttendee->shouldReceive('getContactId')->andReturn(null);

        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->activeCheckInList());
        $this->attendeeRepository->shouldReceive('findAttendeeOnCheckInList')->once()->andReturn($existingAttendee);
        $this->attendeeRepository->shouldReceive('updateFromArray')->once()->with(42, ['email' => 'new@example.com']);
        $this->eventRepository->shouldReceive('findById')->with(789)->andReturn($event);
        $this->contactLinkResolver->shouldReceive('resolveAfterEmailChange')->once()->andReturn($this->resolutionUnchanged());
        $this->attendeeRepository->shouldReceive('findById')->once()->with(42)->andReturn($refreshedAttendee);

        $result = $this->handler->handle('short-id', 'attendee-public-id', [
            'email' => 'new@example.com',
        ]);

        $this->assertSame($refreshedAttendee, $result);

        Mail::assertNothingQueued();
    }
}
