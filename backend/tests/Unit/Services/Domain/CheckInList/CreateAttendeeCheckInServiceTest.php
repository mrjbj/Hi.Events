<?php

namespace Tests\Unit\Services\Domain\CheckInList;

use HiEvents\DataTransferObjects\OrderBalanceDTO;
use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\DomainObjects\Enums\OfflinePaymentMethod;
use HiEvents\DomainObjects\Enums\OrderPaymentType;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Repository\Interfaces\AttendeeCheckInRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\AttendeeAndActionDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\MarkOrderAsPaidDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\RecordOrderPaymentDTO;
use HiEvents\Services\Domain\CheckInList\CheckInListDataService;
use HiEvents\Services\Domain\CheckInList\CreateAttendeeCheckInService;
use HiEvents\Services\Domain\Order\MarkOrderAsPaidService;
use HiEvents\Services\Domain\Order\OrderBalanceService;
use HiEvents\Services\Domain\Order\RecordOrderPaymentService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateAttendeeCheckInServiceTest extends TestCase
{
    private AttendeeCheckInRepositoryInterface|MockInterface $attendeeCheckInRepository;

    private CheckInListDataService|MockInterface $checkInListDataService;

    private EventSettingsRepositoryInterface|MockInterface $eventSettingsRepository;

    private MarkOrderAsPaidService|MockInterface $markOrderAsPaidService;

    private RecordOrderPaymentService|MockInterface $recordOrderPaymentService;

    private OrderBalanceService|MockInterface $orderBalanceService;

    private OrderRepositoryInterface|MockInterface $orderRepository;

    private CreateAttendeeCheckInService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeCheckInRepository = Mockery::mock(AttendeeCheckInRepositoryInterface::class);
        $this->checkInListDataService = Mockery::mock(CheckInListDataService::class);
        $this->eventSettingsRepository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $this->markOrderAsPaidService = Mockery::mock(MarkOrderAsPaidService::class);
        $this->recordOrderPaymentService = Mockery::mock(RecordOrderPaymentService::class);
        $this->orderBalanceService = Mockery::mock(OrderBalanceService::class);
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);

        $db = Mockery::mock(ConnectionInterface::class);
        $db->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

        $this->service = new CreateAttendeeCheckInService(
            $this->attendeeCheckInRepository,
            $this->checkInListDataService,
            $this->eventSettingsRepository,
            $db,
            $this->markOrderAsPaidService,
            $this->recordOrderPaymentService,
            $this->orderBalanceService,
            $this->orderRepository,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_full_door_payment_settles_via_mark_as_paid(): void
    {
        $this->arrangeCheckIn(outstandingBalance: 100.0);

        // Agent collected the full $100 — settles the order (mark-as-paid path,
        // which fires the receipt email / invoice / app fee), recording the
        // entered amount. The partial path is never taken.
        $this->markOrderAsPaidService
            ->shouldReceive('markOrderAsPaid')
            ->once()
            ->with(Mockery::on(fn (MarkOrderAsPaidDTO $dto) => $dto->amountReceived === 100.0
                && $dto->paymentMethod === OfflinePaymentMethod::CASH
                && $dto->recordedByIp === '10.0.0.1'));
        $this->recordOrderPaymentService->shouldNotReceive('record');

        $this->service->checkInAttendees(
            'cil_uuid',
            '10.0.0.1',
            $this->actions(amount: 100.0),
        );

        $this->addToAssertionCount(1);
    }

    public function test_short_door_payment_records_partial_and_leaves_outstanding(): void
    {
        $this->arrangeCheckIn(outstandingBalance: 100.0);

        // Agent collected only $40 — recorded as a partial cash receipt; the order
        // stays awaiting payment (the mark-as-paid settlement path is NOT taken).
        $this->recordOrderPaymentService
            ->shouldReceive('record')
            ->once()
            ->with(Mockery::on(fn (RecordOrderPaymentDTO $dto) => $dto->amount === 40.0
                && $dto->type === OrderPaymentType::CASH
                && $dto->recordedByIp === '10.0.0.1'));
        $this->markOrderAsPaidService->shouldNotReceive('markOrderAsPaid');

        $this->service->checkInAttendees(
            'cil_uuid',
            '10.0.0.1',
            $this->actions(amount: 40.0),
        );

        $this->addToAssertionCount(1);
    }

    public function test_door_payment_without_amount_settles_full_balance(): void
    {
        $this->arrangeCheckIn(outstandingBalance: 100.0);

        // No amount entered → defaults to the full outstanding balance.
        $this->markOrderAsPaidService
            ->shouldReceive('markOrderAsPaid')
            ->once()
            ->with(Mockery::on(fn (MarkOrderAsPaidDTO $dto) => $dto->amountReceived === 100.0));
        $this->recordOrderPaymentService->shouldNotReceive('record');

        $this->service->checkInAttendees(
            'cil_uuid',
            '10.0.0.1',
            $this->actions(amount: null),
        );

        $this->addToAssertionCount(1);
    }

    private function arrangeCheckIn(float $outstandingBalance): void
    {
        $checkInList = Mockery::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->andReturn(null);
        $checkInList->shouldReceive('getEventId')->andReturn(10);
        $checkInList->shouldReceive('getId')->andReturn(5);

        $attendee = Mockery::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getPublicId')->andReturn('A-1');
        $attendee->shouldReceive('getId')->andReturn(13);
        $attendee->shouldReceive('getStatus')->andReturn(AttendeeStatus::AWAITING_PAYMENT->name);
        $attendee->shouldReceive('getEventId')->andReturn(10);
        $attendee->shouldReceive('getOrderId')->andReturn(7);
        $attendee->shouldReceive('getProductId')->andReturn(6);
        $attendee->shouldReceive('getFullName')->andReturn('Sam Spade');

        $this->checkInListDataService->shouldReceive('getCheckInList')->andReturn($checkInList);
        $this->checkInListDataService->shouldReceive('getAttendees')->andReturn(collect([$attendee]));
        $this->checkInListDataService->shouldReceive('verifyAttendeeBelongsToCheckInList');

        $settings = Mockery::mock(EventSettingDomainObject::class);
        $settings->shouldReceive('getAllowOrdersAwaitingOfflinePaymentToCheckIn')->andReturn(true);
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($settings);

        $this->attendeeCheckInRepository->shouldReceive('findWhereIn')->andReturn(new Collection);
        $this->attendeeCheckInRepository->shouldReceive('create')
            ->andReturn(Mockery::mock(AttendeeCheckInDomainObject::class));

        $order = Mockery::mock(OrderDomainObject::class);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($order);

        $this->orderBalanceService->shouldReceive('getBalanceForOrder')->andReturn(
            new OrderBalanceDTO(
                amountOwed: 100.0,
                amountCollected: 0.0,
                totalComps: 0.0,
                totalRefunded: 0.0,
                balance: $outstandingBalance,
                overpaid: 0.0,
                isSettled: false,
            )
        );
    }

    private function actions(?float $amount): Collection
    {
        return collect([
            new AttendeeAndActionDTO(
                public_id: 'A-1',
                action: AttendeeCheckInActionType::CHECK_IN_AND_MARK_ORDER_AS_PAID,
                payment_method: OfflinePaymentMethod::CASH,
                payment_reference: 'collected by J. Doe',
                amount: $amount,
            ),
        ]);
    }
}
