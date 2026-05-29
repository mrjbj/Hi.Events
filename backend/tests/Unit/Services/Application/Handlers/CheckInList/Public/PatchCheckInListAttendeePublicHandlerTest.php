<?php

namespace Tests\Unit\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
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
use Illuminate\Support\Facades\Mail;
use Mockery as m;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class PatchCheckInListAttendeePublicHandlerTest extends TestCase
{
    private CheckInListRepositoryInterface $checkInListRepository;
    private AttendeeRepositoryInterface $attendeeRepository;
    private BundleSeatInfoPropagationService $bundleSeatInfoPropagationService;
    private EventRepositoryInterface $eventRepository;
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

        $this->handler = new PatchCheckInListAttendeePublicHandler(
            $this->attendeeRepository,
            $this->checkInListRepository,
            $this->bundleSeatInfoPropagationService,
            $this->eventRepository,
        );
    }

    public function testThrowsNotFoundWhenCheckInListMissing(): void
    {
        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturnNull();

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle('short-id', 'attendee-public-id', ['first_name' => 'Alice']);
    }

    public function testThrowsCannotCheckInWhenListExpired(): void
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

    public function testThrowsNotFoundWhenAttendeeNotOnList(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->once()->andReturn(null);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->attendeeRepository
            ->shouldReceive('findAttendeeOnCheckInList')
            ->once()
            ->with('short-id', 'attendee-public-id')
            ->andReturnNull();

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle('short-id', 'attendee-public-id', ['first_name' => 'Alice']);
    }

    public function testUpdatesProvidedFieldsAndReturnsRefreshedAttendee(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->once()->andReturn(null);

        $existingAttendee = m::mock(AttendeeDomainObject::class);
        $existingAttendee->shouldReceive('getId')->andReturn(42);
        $existingAttendee->shouldReceive('getSeatInfo')->andReturn(null);
        $existingAttendee->shouldReceive('getConfirmAtCheckin')->andReturn(false);
        $existingAttendee->shouldReceive('getEmail')->andReturn('old@example.com');

        $refreshedAttendee = m::mock(AttendeeDomainObject::class);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->attendeeRepository
            ->shouldReceive('findAttendeeOnCheckInList')
            ->once()
            ->with('short-id', 'attendee-public-id')
            ->andReturn($existingAttendee);

        $this->attendeeRepository
            ->shouldReceive('updateFromArray')
            ->once()
            ->with(42, [
                'first_name' => 'Alice',
                'email' => 'alice@example.com',
            ]);

        $this->attendeeRepository
            ->shouldReceive('findById')
            ->once()
            ->with(42)
            ->andReturn($refreshedAttendee);

        $result = $this->handler->handle('short-id', 'attendee-public-id', [
            'first_name' => 'Alice',
            'email' => 'alice@example.com',
        ]);

        $this->assertSame($refreshedAttendee, $result);
    }

    public function testSkipsUpdateWhenAllFieldsBlankButStillReturnsAttendee(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->once()->andReturn(null);

        $existingAttendee = m::mock(AttendeeDomainObject::class);
        $existingAttendee->shouldReceive('getId')->andReturn(42);
        $existingAttendee->shouldReceive('getSeatInfo')->andReturn(null);
        $existingAttendee->shouldReceive('getEmail')->andReturn('old@example.com');

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->attendeeRepository
            ->shouldReceive('findAttendeeOnCheckInList')
            ->once()
            ->andReturn($existingAttendee);

        $this->attendeeRepository
            ->shouldNotReceive('updateFromArray');

        $this->attendeeRepository
            ->shouldReceive('findById')
            ->once()
            ->with(42)
            ->andReturn($existingAttendee);

        $result = $this->handler->handle('short-id', 'attendee-public-id', [
            'first_name' => '',
            'last_name' => null,
        ]);

        $this->assertSame($existingAttendee, $result);
    }

    public function testPersistsConfirmAtCheckinFalseWhenExplicitlyProvided(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->once()->andReturn(null);

        $existingAttendee = m::mock(AttendeeDomainObject::class);
        $existingAttendee->shouldReceive('getId')->andReturn(42);
        $existingAttendee->shouldReceive('getSeatInfo')->andReturn(null);
        $existingAttendee->shouldReceive('getEmail')->andReturn('old@example.com');

        $refreshedAttendee = m::mock(AttendeeDomainObject::class);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->attendeeRepository
            ->shouldReceive('findAttendeeOnCheckInList')
            ->once()
            ->andReturn($existingAttendee);

        $this->attendeeRepository
            ->shouldReceive('updateFromArray')
            ->once()
            ->with(42, [
                'first_name' => 'Alice',
                'last_name' => 'Walker',
                'email' => 'alice@example.com',
                'confirm_at_checkin' => false,
            ]);

        $this->attendeeRepository
            ->shouldReceive('findById')
            ->once()
            ->with(42)
            ->andReturn($refreshedAttendee);

        $result = $this->handler->handle('short-id', 'attendee-public-id', [
            'first_name' => 'Alice',
            'last_name' => 'Walker',
            'email' => 'alice@example.com',
            'confirm_at_checkin' => false,
        ]);

        $this->assertSame($refreshedAttendee, $result);
    }

    public function testPersistsConfirmAtCheckinTrueWhenProvidedAlone(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->once()->andReturn(null);

        $existingAttendee = m::mock(AttendeeDomainObject::class);
        $existingAttendee->shouldReceive('getId')->andReturn(42);
        $existingAttendee->shouldReceive('getSeatInfo')->andReturn(null);
        $existingAttendee->shouldReceive('getEmail')->andReturn('old@example.com');

        $refreshedAttendee = m::mock(AttendeeDomainObject::class);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->attendeeRepository
            ->shouldReceive('findAttendeeOnCheckInList')
            ->once()
            ->andReturn($existingAttendee);

        $this->attendeeRepository
            ->shouldReceive('updateFromArray')
            ->once()
            ->with(42, [
                'confirm_at_checkin' => true,
            ]);

        $this->attendeeRepository
            ->shouldReceive('findById')
            ->once()
            ->with(42)
            ->andReturn($refreshedAttendee);

        $result = $this->handler->handle('short-id', 'attendee-public-id', [
            'confirm_at_checkin' => true,
        ]);

        $this->assertSame($refreshedAttendee, $result);
    }

    public function testNotifiesOldEmailWhenEmailChangedAndNotifyRequested(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->once()->andReturn(null);

        $existingAttendee = m::mock(AttendeeDomainObject::class);
        $existingAttendee->shouldReceive('getId')->andReturn(42);
        $existingAttendee->shouldReceive('getSeatInfo')->andReturn(null);
        $existingAttendee->shouldReceive('getEmail')->andReturn('old@example.com');
        $existingAttendee->shouldReceive('getEventId')->andReturn(789);

        $product = m::mock(ProductDomainObject::class);
        $product->shouldReceive('getTitle')->andReturn('General Admission');
        $attendeeWithProduct = m::mock(AttendeeDomainObject::class);
        $attendeeWithProduct->shouldReceive('getProduct')->andReturn($product);

        $eventSettings = m::mock(EventSettingDomainObject::class);
        $eventSettings->shouldReceive('getSupportEmail')->andReturn('support@example.com');
        $organizer = m::mock(OrganizerDomainObject::class);
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getOrganizer')->andReturn($organizer);
        $event->shouldReceive('getEventSettings')->andReturn($eventSettings);

        $refreshedAttendee = m::mock(AttendeeDomainObject::class);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->attendeeRepository
            ->shouldReceive('findAttendeeOnCheckInList')
            ->once()
            ->andReturn($existingAttendee);

        $this->attendeeRepository
            ->shouldReceive('updateFromArray')
            ->once()
            ->with(42, ['email' => 'new@example.com']);

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->with(789)->andReturn($event);

        $this->attendeeRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->attendeeRepository
            ->shouldReceive('findById')
            ->with(42)
            ->andReturn($attendeeWithProduct, $refreshedAttendee);

        $result = $this->handler->handle('short-id', 'attendee-public-id', [
            'email' => 'new@example.com',
            'notify_email_change' => true,
        ]);

        $this->assertSame($refreshedAttendee, $result);

        Mail::assertQueued(AttendeeDetailsChangedMail::class, fn ($mail) => $mail->hasTo('old@example.com'));
    }

    public function testDoesNotNotifyWhenNotifyFlagAbsent(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->once()->andReturn(null);

        $existingAttendee = m::mock(AttendeeDomainObject::class);
        $existingAttendee->shouldReceive('getId')->andReturn(42);
        $existingAttendee->shouldReceive('getSeatInfo')->andReturn(null);
        $existingAttendee->shouldReceive('getEmail')->andReturn('old@example.com');

        $refreshedAttendee = m::mock(AttendeeDomainObject::class);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->attendeeRepository
            ->shouldReceive('findAttendeeOnCheckInList')
            ->once()
            ->andReturn($existingAttendee);

        $this->attendeeRepository
            ->shouldReceive('updateFromArray')
            ->once()
            ->with(42, ['email' => 'new@example.com']);

        $this->attendeeRepository
            ->shouldReceive('findById')
            ->once()
            ->with(42)
            ->andReturn($refreshedAttendee);

        $result = $this->handler->handle('short-id', 'attendee-public-id', [
            'email' => 'new@example.com',
        ]);

        $this->assertSame($refreshedAttendee, $result);

        Mail::assertNothingQueued();
    }
}
