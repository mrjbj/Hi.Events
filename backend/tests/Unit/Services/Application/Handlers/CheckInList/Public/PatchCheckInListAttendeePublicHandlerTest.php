<?php

namespace Tests\Unit\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\Public\PatchCheckInListAttendeePublicHandler;
use HiEvents\Services\Domain\Attendee\BundleSeatInfoPropagationService;
use Mockery as m;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class PatchCheckInListAttendeePublicHandlerTest extends TestCase
{
    private CheckInListRepositoryInterface $checkInListRepository;
    private AttendeeRepositoryInterface $attendeeRepository;
    private BundleSeatInfoPropagationService $bundleSeatInfoPropagationService;
    private PatchCheckInListAttendeePublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkInListRepository = m::mock(CheckInListRepositoryInterface::class);
        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);
        $this->bundleSeatInfoPropagationService = m::mock(BundleSeatInfoPropagationService::class);
        $this->bundleSeatInfoPropagationService->shouldReceive('propagate')->byDefault();

        $this->handler = new PatchCheckInListAttendeePublicHandler(
            $this->attendeeRepository,
            $this->checkInListRepository,
            $this->bundleSeatInfoPropagationService,
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
}
