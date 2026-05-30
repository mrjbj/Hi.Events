<?php

namespace Tests\Unit\Services\Domain\Order;

use HiEvents\DataTransferObjects\OrderBalanceDTO;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Order\ApplyOrderBalanceStatusService;
use HiEvents\Services\Domain\Order\OrderBalanceService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ApplyOrderBalanceStatusServiceTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orderRepository;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private OrderBalanceService|MockInterface $orderBalanceService;

    private ApplyOrderBalanceStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->orderBalanceService = Mockery::mock(OrderBalanceService::class);

        $this->service = new ApplyOrderBalanceStatusService(
            $this->orderRepository,
            $this->attendeeRepository,
            $this->orderBalanceService,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_settled_order_completes_and_activates_attendees(): void
    {
        $order = $this->order();
        $this->orderBalanceService->shouldReceive('getBalanceForOrder')->with($order)
            ->andReturn($this->balance(owed: 100.0, balance: 0.0, isSettled: true));

        $this->orderRepository->shouldReceive('updateFromArray')->once()
            ->with(1, Mockery::on(fn (array $a) => $a[OrderDomainObjectAbstract::STATUS] === OrderStatus::COMPLETED->name
                && $a[OrderDomainObjectAbstract::PAYMENT_STATUS] === OrderPaymentStatus::PAYMENT_RECEIVED->name));

        $this->attendeeRepository->shouldReceive('updateWhere')->once();

        $this->service->apply($order);
        $this->addToAssertionCount(1);
    }

    public function test_partial_payment_keeps_order_awaiting_and_does_not_activate(): void
    {
        $order = $this->order();
        $this->orderBalanceService->shouldReceive('getBalanceForOrder')->with($order)
            ->andReturn($this->balance(owed: 100.0, balance: 85.0, isSettled: false));

        $this->orderRepository->shouldReceive('updateFromArray')->once()
            ->with(1, Mockery::on(fn (array $a) => $a[OrderDomainObjectAbstract::STATUS] === OrderStatus::AWAITING_OFFLINE_PAYMENT->name
                && $a[OrderDomainObjectAbstract::PAYMENT_STATUS] === OrderPaymentStatus::AWAITING_OFFLINE_PAYMENT->name));

        $this->attendeeRepository->shouldNotReceive('updateWhere');

        $this->service->apply($order);
        $this->addToAssertionCount(1);
    }

    public function test_zero_owed_order_is_marked_no_payment_required(): void
    {
        $order = $this->order();
        $this->orderBalanceService->shouldReceive('getBalanceForOrder')->with($order)
            ->andReturn($this->balance(owed: 0.0, balance: 0.0, isSettled: true));

        $this->orderRepository->shouldReceive('updateFromArray')->once()
            ->with(1, Mockery::on(fn (array $a) => $a[OrderDomainObjectAbstract::STATUS] === OrderStatus::COMPLETED->name
                && $a[OrderDomainObjectAbstract::PAYMENT_STATUS] === OrderPaymentStatus::NO_PAYMENT_REQUIRED->name));

        $this->attendeeRepository->shouldReceive('updateWhere')->once();

        $this->service->apply($order);
        $this->addToAssertionCount(1);
    }

    private function order(): OrderDomainObject
    {
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(1);
        return $order;
    }

    private function balance(float $owed, float $balance, bool $isSettled): OrderBalanceDTO
    {
        return new OrderBalanceDTO(
            amountOwed: $owed,
            amountCollected: $owed - $balance,
            totalComps: 0.0,
            totalRefunded: 0.0,
            balance: $balance,
            overpaid: 0.0,
            isSettled: $isSettled,
        );
    }
}
