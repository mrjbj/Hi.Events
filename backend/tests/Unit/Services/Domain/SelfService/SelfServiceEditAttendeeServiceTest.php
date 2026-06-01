<?php

namespace Tests\Unit\Services\Domain\SelfService;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\AttendeeContactResolutionAction;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Mail\Attendee\AttendeeDetailsChangedMail;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Domain\Contact\AttendeeContactLinkResolver;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use HiEvents\Services\Domain\Contact\DTO\AttendeeContactResolutionDTO;
use HiEvents\Services\Domain\Email\EmailSuppressionService;
use HiEvents\Services\Domain\SelfService\OrderAuditLogService;
use HiEvents\Services\Domain\SelfService\SelfServiceEditAttendeeService;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class SelfServiceEditAttendeeServiceTest extends TestCase
{
    private SelfServiceEditAttendeeService $service;

    private MockInterface|AttendeeRepositoryInterface $attendeeRepository;

    private MockInterface|EventRepositoryInterface $eventRepository;

    private MockInterface|OrderAuditLogService $orderAuditLogService;

    private MockInterface|SendAttendeeTicketService $sendAttendeeTicketService;

    private MockInterface|AttendeeContactLinkResolver $contactLinkResolver;

    private MockInterface|ContactSignedTokenService $contactTokenService;

    private MockInterface|LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->orderAuditLogService = Mockery::mock(OrderAuditLogService::class);
        $this->sendAttendeeTicketService = Mockery::mock(SendAttendeeTicketService::class);
        $this->contactLinkResolver = Mockery::mock(AttendeeContactLinkResolver::class);
        $this->contactTokenService = Mockery::mock(ContactSignedTokenService::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->logger->shouldReceive('warning')->byDefault();
        $this->contactTokenService->shouldReceive('generate')->byDefault()->andReturn('mock-token');

        // The contact-link reconciliation is exercised in its own resolver test;
        // here it's a benign no-op unless a test overrides it.
        $this->contactLinkResolver
            ->shouldReceive('resolveAfterEmailChange')
            ->byDefault()
            ->andReturn(new AttendeeContactResolutionDTO(AttendeeContactResolutionAction::UNCHANGED, null, false));

        $emailSuppressionService = Mockery::mock(EmailSuppressionService::class);
        $emailSuppressionService->shouldReceive('isEmailSuppressed')->byDefault()->andReturn(false);

        $this->service = new SelfServiceEditAttendeeService(
            $this->attendeeRepository,
            $this->eventRepository,
            $this->orderAuditLogService,
            $this->sendAttendeeTicketService,
            $this->contactLinkResolver,
            $this->contactTokenService,
            $emailSuppressionService,
            $this->logger,
        );
    }

    public function test_successful_edit_updates_attendee_fields(): void
    {
        $attendee = Mockery::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getId')->andReturn(456);
        $attendee->shouldReceive('getEventId')->andReturn(789);
        $attendee->shouldReceive('getContactId')->andReturn(0);
        $attendee->shouldReceive('getFirstName')->andReturn('John');
        $attendee->shouldReceive('getLastName')->andReturn('Doe');
        $attendee->shouldReceive('getEmail')->andReturn('old@example.com');

        $this->attendeeRepository
            ->shouldReceive('updateWhere')
            ->once()
            ->withArgs(function ($attributes, $where) {
                return $attributes === [
                    'first_name' => 'Jane',
                    'last_name' => 'Smith',
                ]
                && $where === ['id' => 456];
            })
            ->andReturn(1);

        // Name-only edit: no email change, so the resolver is not consulted.
        $this->contactLinkResolver->shouldNotReceive('resolveAfterEmailChange');

        $mockProduct = Mockery::mock(ProductDomainObject::class);
        $mockProduct->shouldReceive('getTitle')->andReturn('General Admission');

        $mockAttendeeWithProduct = Mockery::mock(AttendeeDomainObject::class);
        $mockAttendeeWithProduct->shouldReceive('getProduct')->andReturn($mockProduct);

        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository->shouldReceive('findById')->with(456)->andReturn($mockAttendeeWithProduct);

        $event = $this->mockEvent();
        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->with(789)->andReturn($event);

        $this->orderAuditLogService
            ->shouldReceive('logAttendeeUpdate')
            ->once()
            ->withArgs(function ($att, $oldValues, $newValues, $ip, $ua) use ($attendee) {
                return $att === $attendee
                    && $oldValues === ['first_name' => 'John', 'last_name' => 'Doe']
                    && $newValues === ['first_name' => 'Jane', 'last_name' => 'Smith']
                    && $ip === '192.168.1.1'
                    && $ua === 'Mozilla/5.0';
            });

        $result = $this->service->editAttendee(
            attendee: $attendee,
            firstName: 'Jane',
            lastName: 'Smith',
            email: null,
            ipAddress: '192.168.1.1',
            userAgent: 'Mozilla/5.0'
        );

        $this->assertTrue($result->success);
        $this->assertFalse($result->emailChanged);

        Mail::assertQueued(AttendeeDetailsChangedMail::class, fn ($mail) => $mail->hasTo('old@example.com'));
    }

    public function test_email_change_triggers_short_id_rotation_and_resolves_contact(): void
    {
        $attendee = Mockery::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getId')->andReturn(456);
        $attendee->shouldReceive('getEventId')->andReturn(789);
        $attendee->shouldReceive('getContactId')->andReturn(0);
        $attendee->shouldReceive('getFirstName')->andReturn('John');
        $attendee->shouldReceive('getLastName')->andReturn('Doe');
        $attendee->shouldReceive('getEmail')->andReturn('old@example.com');
        $attendee->shouldReceive('getShortId')->andReturn('a_oldshortid123');

        $this->attendeeRepository
            ->shouldReceive('updateWhere')
            ->once()
            ->withArgs(function ($attributes, $where) {
                return isset($attributes['email'])
                    && $attributes['email'] === 'new@example.com'
                    && str_starts_with($attributes['short_id'] ?? '', 'a_')
                    && $where === ['id' => 456];
            })
            ->andReturn(1);

        // Self-service renames a sole-owner contact in place.
        $this->contactLinkResolver
            ->shouldReceive('resolveAfterEmailChange')
            ->once()
            ->withArgs(function (...$args) {
                return $args[0] === 456                        // attendeeId
                    && $args[3] === 'new@example.com'          // newEmail
                    && ($args[9] ?? null) === true;            // renameSoleOwnerContact
            })
            ->andReturn(new AttendeeContactResolutionDTO(AttendeeContactResolutionAction::CONTACT_RENAMED, 0, false));

        $mockOrder = Mockery::mock(OrderDomainObject::class);
        $mockProduct = Mockery::mock(ProductDomainObject::class);
        $mockProduct->shouldReceive('getTitle')->andReturn('General Admission');
        $mockAttendeeWithRelations = Mockery::mock(AttendeeDomainObject::class);
        $mockAttendeeWithRelations->shouldReceive('getOrder')->andReturn($mockOrder);
        $mockAttendeeWithRelations->shouldReceive('getProduct')->andReturn($mockProduct);

        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository->shouldReceive('findById')->with(456)->andReturn($mockAttendeeWithRelations);

        $event = $this->mockEvent();
        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->with(789)->andReturn($event);

        $this->sendAttendeeTicketService->shouldReceive('send')->once();
        $this->orderAuditLogService->shouldReceive('logAttendeeUpdate')->once();

        $result = $this->service->editAttendee(
            attendee: $attendee,
            firstName: null,
            lastName: null,
            email: 'new@example.com',
            ipAddress: '192.168.1.1',
            userAgent: 'Mozilla/5.0'
        );

        $this->assertTrue($result->success);
        $this->assertTrue($result->shortIdChanged);
        $this->assertTrue($result->emailChanged);
    }

    public function test_split_resolution_mints_new_contact_token(): void
    {
        $attendee = Mockery::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getId')->andReturn(456);
        $attendee->shouldReceive('getEventId')->andReturn(789);
        $attendee->shouldReceive('getContactId')->andReturn(5);
        $attendee->shouldReceive('getFirstName')->andReturn('John');
        $attendee->shouldReceive('getLastName')->andReturn('Doe');
        $attendee->shouldReceive('getEmail')->andReturn('shared@example.com');
        $attendee->shouldReceive('getShortId')->andReturn('a_oldshortid123');

        $this->attendeeRepository->shouldReceive('updateWhere')->once()->andReturn(1);

        $this->contactLinkResolver
            ->shouldReceive('resolveAfterEmailChange')
            ->once()
            ->andReturn(new AttendeeContactResolutionDTO(AttendeeContactResolutionAction::SPLIT, 77, true));

        // linkChanged → a fresh token is minted for the new contact.
        $this->contactTokenService->shouldReceive('generate')->once()->with(77, 1)->andReturn('split-token');

        $mockOrder = Mockery::mock(OrderDomainObject::class);
        $mockProduct = Mockery::mock(ProductDomainObject::class);
        $mockProduct->shouldReceive('getTitle')->andReturn('GA');
        $mockAttendeeWithRelations = Mockery::mock(AttendeeDomainObject::class);
        $mockAttendeeWithRelations->shouldReceive('getOrder')->andReturn($mockOrder);
        $mockAttendeeWithRelations->shouldReceive('getProduct')->andReturn($mockProduct);

        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository->shouldReceive('findById')->with(456)->andReturn($mockAttendeeWithRelations);

        $event = $this->mockEvent();
        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->with(789)->andReturn($event);

        $this->sendAttendeeTicketService->shouldReceive('send')->once();
        $this->orderAuditLogService->shouldReceive('logAttendeeUpdate')->once();

        $result = $this->service->editAttendee(
            attendee: $attendee,
            firstName: null,
            lastName: null,
            email: 'mine@example.com',
            ipAddress: '192.168.1.1',
            userAgent: 'Mozilla/5.0'
        );

        $this->assertTrue($result->emailChanged);
        $this->assertSame('split-token', $result->newContactToken);
    }

    public function test_assigning_email_to_contactless_attendee_does_not_notify_blank_old_address(): void
    {
        // An unassigned/contactless seat starts with a blank email. Assigning
        // one is an email "change" from '' to a real address — but there is no
        // previous holder to notify, and Mail::to('') would throw. The service
        // must skip the change notification (while still sending the ticket).
        $attendee = Mockery::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getId')->andReturn(456);
        $attendee->shouldReceive('getEventId')->andReturn(789);
        $attendee->shouldReceive('getContactId')->andReturn(null);
        $attendee->shouldReceive('getFirstName')->andReturn('');
        $attendee->shouldReceive('getLastName')->andReturn('');
        $attendee->shouldReceive('getEmail')->andReturn('');
        $attendee->shouldReceive('getShortId')->andReturn('a_oldshortid123');

        $this->attendeeRepository->shouldReceive('updateWhere')->once()->andReturn(1);

        $this->contactLinkResolver
            ->shouldReceive('resolveAfterEmailChange')
            ->once()
            ->andReturn(new AttendeeContactResolutionDTO(AttendeeContactResolutionAction::LINKED, 99, true));

        $this->contactTokenService->shouldReceive('generate')->andReturn('linked-token');

        $mockOrder = Mockery::mock(OrderDomainObject::class);
        $mockProduct = Mockery::mock(ProductDomainObject::class);
        $mockProduct->shouldReceive('getTitle')->andReturn('GA');
        $mockAttendeeWithRelations = Mockery::mock(AttendeeDomainObject::class);
        $mockAttendeeWithRelations->shouldReceive('getOrder')->andReturn($mockOrder);
        $mockAttendeeWithRelations->shouldReceive('getProduct')->andReturn($mockProduct);

        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository->shouldReceive('findById')->with(456)->andReturn($mockAttendeeWithRelations);

        $event = $this->mockEvent();
        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->with(789)->andReturn($event);

        $this->sendAttendeeTicketService->shouldReceive('send')->once();
        $this->orderAuditLogService->shouldReceive('logAttendeeUpdate')->once();

        $result = $this->service->editAttendee(
            attendee: $attendee,
            firstName: 'New',
            lastName: 'Guest',
            email: 'guest@example.com',
            ipAddress: '192.168.1.1',
            userAgent: 'Mozilla/5.0'
        );

        $this->assertTrue($result->emailChanged);
        Mail::assertNotQueued(AttendeeDetailsChangedMail::class);
    }

    public function test_no_update_when_no_fields_change(): void
    {
        $attendee = Mockery::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getId')->andReturn(456);
        $attendee->shouldReceive('getFirstName')->andReturn('John');
        $attendee->shouldReceive('getLastName')->andReturn('Doe');
        $attendee->shouldReceive('getEmail')->andReturn('same@example.com');

        $this->attendeeRepository->shouldReceive('updateWhere')->never();
        $this->contactLinkResolver->shouldNotReceive('resolveAfterEmailChange');
        $this->orderAuditLogService->shouldReceive('logAttendeeUpdate')->never();

        $result = $this->service->editAttendee(
            attendee: $attendee,
            firstName: 'John',
            lastName: 'Doe',
            email: 'same@example.com',
            ipAddress: '192.168.1.1',
            userAgent: 'Mozilla/5.0'
        );

        $this->assertTrue($result->success);
        $this->assertFalse($result->emailChanged);

        Mail::assertNothingSent();
    }

    public function test_confirm_at_checkin_toggle_persists_without_email(): void
    {
        $attendee = Mockery::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getId')->andReturn(456);
        $attendee->shouldReceive('getFirstName')->andReturn('John');
        $attendee->shouldReceive('getLastName')->andReturn('Doe');
        $attendee->shouldReceive('getEmail')->andReturn('same@example.com');
        $attendee->shouldReceive('getSeatInfo')->andReturn(null);
        $attendee->shouldReceive('getConfirmAtCheckin')->andReturn(false);

        $this->attendeeRepository
            ->shouldReceive('updateWhere')
            ->once()
            ->withArgs(fn ($attributes, $where) => $attributes === ['confirm_at_checkin' => true] && $where === ['id' => 456])
            ->andReturn(1);

        $this->contactLinkResolver->shouldNotReceive('resolveAfterEmailChange');
        $this->orderAuditLogService->shouldReceive('logAttendeeUpdate')->once();

        $result = $this->service->editAttendee(
            attendee: $attendee,
            firstName: 'John',
            lastName: 'Doe',
            email: 'same@example.com',
            ipAddress: '192.168.1.1',
            userAgent: 'Mozilla/5.0',
            confirmAtCheckin: true,
        );

        $this->assertTrue($result->success);
        $this->assertFalse($result->emailChanged);

        Mail::assertNothingSent();
    }

    private function mockEvent(): EventDomainObject
    {
        $eventSettings = Mockery::mock(EventSettingDomainObject::class);
        $eventSettings->shouldReceive('getSupportEmail')->andReturn('support@example.com');
        $organizer = Mockery::mock(OrganizerDomainObject::class);
        $event = Mockery::mock(EventDomainObject::class);
        $event->shouldReceive('getEventSettings')->andReturn($eventSettings);
        $event->shouldReceive('getOrganizer')->andReturn($organizer);
        $event->shouldReceive('getAccountId')->andReturn(1);
        $event->shouldReceive('getAccountId')->andReturn(1);

        return $event;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
