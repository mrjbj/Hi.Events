<?php

namespace Tests\Unit\Services\Domain\Order;

use HiEvents\DomainObjects\Enums\OrderPaymentType;
use HiEvents\DomainObjects\Generated\OrderPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderPaymentDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\OrderPaymentRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\ReverseOrderPaymentDTO;
use HiEvents\Services\Domain\Order\ApplyOrderBalanceStatusService;
use HiEvents\Services\Domain\Order\ReverseOrderPaymentService;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ReverseOrderPaymentServiceTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orderRepository;

    private OrderPaymentRepositoryInterface|MockInterface $orderPaymentRepository;

    private ApplyOrderBalanceStatusService|MockInterface $applyOrderBalanceStatusService;

    private ReverseOrderPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->orderPaymentRepository = Mockery::mock(OrderPaymentRepositoryInterface::class);
        $this->applyOrderBalanceStatusService = Mockery::mock(ApplyOrderBalanceStatusService::class);

        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

        $this->service = new ReverseOrderPaymentService(
            $this->orderRepository,
            $this->orderPaymentRepository,
            $this->applyOrderBalanceStatusService,
            $databaseManager,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_reverses_payment_with_negative_amount_of_the_same_type(): void
    {
        $order = $this->order(OrderStatus::COMPLETED->name);
        $original = $this->payment(id: 11, orderId: 7, type: OrderPaymentType::CASH->value, amount: 40.0);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->once()->andReturn($order);
        $this->orderRepository->shouldReceive('findById')->andReturn($order);

        $this->orderPaymentRepository->shouldReceive('findFirstWhere')->once()
            ->with([OrderPaymentDomainObjectAbstract::ID => 11])
            ->andReturn($original);
        $this->orderPaymentRepository->shouldReceive('findFirstWhere')->once()
            ->with([OrderPaymentDomainObjectAbstract::REVERSES_PAYMENT_ID => 11])
            ->andReturn(null);

        $this->orderPaymentRepository->shouldReceive('create')->once()
            ->with(Mockery::on(fn (array $a) => $a[OrderPaymentDomainObjectAbstract::ORDER_ID] === 7
                && $a[OrderPaymentDomainObjectAbstract::REVERSES_PAYMENT_ID] === 11
                && $a[OrderPaymentDomainObjectAbstract::TYPE] === OrderPaymentType::CASH->value
                && $a[OrderPaymentDomainObjectAbstract::AMOUNT] === -40.0
                && $a[OrderPaymentDomainObjectAbstract::NOTE] === 'mistyped amount'));

        $this->applyOrderBalanceStatusService->shouldReceive('apply')->once()->with($order);

        $result = $this->service->reverse($this->dto(11));

        $this->assertSame($order, $result);
    }

    public function test_throws_when_order_not_found(): void
    {
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->once()->andReturn(null);

        $this->expectException(ResourceNotFoundException::class);

        $this->service->reverse($this->dto(11));
    }

    public function test_throws_when_order_in_uneditable_status(): void
    {
        $order = $this->order(OrderStatus::CANCELLED->name);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->once()->andReturn($order);

        $this->orderPaymentRepository->shouldNotReceive('create');

        $this->expectException(ResourceConflictException::class);

        $this->service->reverse($this->dto(11));
    }

    public function test_throws_when_payment_not_found(): void
    {
        $order = $this->order(OrderStatus::COMPLETED->name);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->once()->andReturn($order);

        $this->orderPaymentRepository->shouldReceive('findFirstWhere')->once()
            ->with([OrderPaymentDomainObjectAbstract::ID => 11])
            ->andReturn(null);
        $this->orderPaymentRepository->shouldNotReceive('create');

        $this->expectException(ResourceNotFoundException::class);

        $this->service->reverse($this->dto(11));
    }

    public function test_throws_when_payment_belongs_to_a_different_order(): void
    {
        $order = $this->order(OrderStatus::COMPLETED->name);
        $original = $this->payment(id: 11, orderId: 999, type: OrderPaymentType::CASH->value, amount: 40.0);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->once()->andReturn($order);

        $this->orderPaymentRepository->shouldReceive('findFirstWhere')->once()
            ->with([OrderPaymentDomainObjectAbstract::ID => 11])
            ->andReturn($original);
        $this->orderPaymentRepository->shouldNotReceive('create');

        $this->expectException(ResourceNotFoundException::class);

        $this->service->reverse($this->dto(11));
    }

    public function test_throws_when_payment_is_itself_a_reversal(): void
    {
        $order = $this->order(OrderStatus::COMPLETED->name);
        $original = $this->payment(id: 11, orderId: 7, type: OrderPaymentType::CASH->value, amount: -40.0, reversesPaymentId: 5);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->once()->andReturn($order);

        $this->orderPaymentRepository->shouldReceive('findFirstWhere')->once()
            ->with([OrderPaymentDomainObjectAbstract::ID => 11])
            ->andReturn($original);
        $this->orderPaymentRepository->shouldNotReceive('create');

        $this->expectException(ResourceConflictException::class);

        $this->service->reverse($this->dto(11));
    }

    public function test_throws_when_payment_already_reversed(): void
    {
        $order = $this->order(OrderStatus::COMPLETED->name);
        $original = $this->payment(id: 11, orderId: 7, type: OrderPaymentType::CASH->value, amount: 40.0);
        $existingReversal = $this->payment(id: 12, orderId: 7, type: OrderPaymentType::CASH->value, amount: -40.0, reversesPaymentId: 11);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->once()->andReturn($order);

        $this->orderPaymentRepository->shouldReceive('findFirstWhere')->once()
            ->with([OrderPaymentDomainObjectAbstract::ID => 11])
            ->andReturn($original);
        $this->orderPaymentRepository->shouldReceive('findFirstWhere')->once()
            ->with([OrderPaymentDomainObjectAbstract::REVERSES_PAYMENT_ID => 11])
            ->andReturn($existingReversal);
        $this->orderPaymentRepository->shouldNotReceive('create');

        $this->expectException(ResourceConflictException::class);

        $this->service->reverse($this->dto(11));
    }

    private function order(string $status): OrderDomainObject|MockInterface
    {
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(7);
        $order->shouldReceive('getStatus')->andReturn($status);

        return $order;
    }

    private function payment(
        int $id,
        int $orderId,
        string $type,
        float $amount,
        ?int $reversesPaymentId = null,
    ): OrderPaymentDomainObject|MockInterface {
        $payment = Mockery::mock(OrderPaymentDomainObject::class);
        $payment->shouldReceive('getId')->andReturn($id);
        $payment->shouldReceive('getOrderId')->andReturn($orderId);
        $payment->shouldReceive('getType')->andReturn($type);
        $payment->shouldReceive('getAmount')->andReturn($amount);
        $payment->shouldReceive('getCurrency')->andReturn('USD');
        $payment->shouldReceive('getReference')->andReturn(null);
        $payment->shouldReceive('getReversesPaymentId')->andReturn($reversesPaymentId);

        return $payment;
    }

    private function dto(int $paymentId): ReverseOrderPaymentDTO
    {
        return new ReverseOrderPaymentDTO(
            eventId: 3,
            orderId: 7,
            paymentId: $paymentId,
            note: 'mistyped amount',
            recordedByUserId: 9,
            recordedByIp: '127.0.0.1',
        );
    }
}
