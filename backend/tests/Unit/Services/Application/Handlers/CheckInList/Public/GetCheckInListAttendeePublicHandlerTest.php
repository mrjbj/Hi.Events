<?php

namespace Tests\Unit\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\Public\GetCheckInListAttendeePublicHandler;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use Mockery as m;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class GetCheckInListAttendeePublicHandlerTest extends TestCase
{
    private CheckInListRepositoryInterface $checkInListRepository;
    private AttendeeRepositoryInterface $attendeeRepository;
    private ContactSignedTokenService $contactTokenService;
    private GetCheckInListAttendeePublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkInListRepository = m::mock(CheckInListRepositoryInterface::class);
        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);
        $this->contactTokenService = m::mock(ContactSignedTokenService::class);

        $this->handler = new GetCheckInListAttendeePublicHandler(
            $this->attendeeRepository,
            $this->checkInListRepository,
            $this->contactTokenService,
        );
    }

    public function testHandleThrowsNotFoundIfCheckInListMissing(): void
    {
        $this->checkInListRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturnNull();

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle('short-id', 'attendee-public-id');
    }

    public function testHandleThrowsCannotCheckInIfListExpired(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->twice()->andReturn(now()->subMinute());

        $this->checkInListRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->expectException(CannotCheckInException::class);

        $this->handler->handle('short-id', 'attendee-public-id');
    }

    public function testHandleThrowsCannotCheckInIfListNotActiveYet(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->twice()->andReturn(now()->addMinute());

        $this->checkInListRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->expectException(CannotCheckInException::class);

        $this->handler->handle('short-id', 'attendee-public-id');
    }

    public function testHandleReturnsAttendeeSuccessfully(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getEventId')->once()->andReturn(123);
        $checkInList->shouldReceive('getEvent')->andReturn(null);

        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getContactId')->andReturn(null);

        $this->checkInListRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->attendeeRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf();

        $this->attendeeRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                'public_id' => 'attendee-public-id',
                'event_id' => 123,
            ])
            ->andReturn($attendee);

        $result = $this->handler->handle('short-id', 'attendee-public-id');

        $this->assertSame($attendee, $result);
    }

    public function testHandleAttachesContactTokenWhenAttendeeIsLinkedToContact(): void
    {
        $event = m::mock(\HiEvents\DomainObjects\EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(7);

        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getEventId')->once()->andReturn(123);
        $checkInList->shouldReceive('getEvent')->andReturn($event);

        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getContactId')->andReturn(42);
        $attendee->shouldReceive('setContactToken')
            ->once()
            ->with('minted-token')
            ->andReturnSelf();

        $this->contactTokenService
            ->shouldReceive('generate')
            ->once()
            ->with(42, 7)
            ->andReturn('minted-token');

        $this->checkInListRepository->shouldReceive('loadRelation')->andReturnSelf()->times(2);
        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($checkInList);

        $this->attendeeRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf();

        $this->attendeeRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($attendee);

        $this->handler->handle('short-id', 'attendee-public-id');

        $this->assertTrue(true);
    }
}
