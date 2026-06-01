<?php

namespace Tests\Unit\Services\Domain\Order;

use HiEvents\DataTransferObjects\OrderBalanceDTO;
use HiEvents\DomainObjects\Enums\OfflinePaymentMethod;
use HiEvents\DomainObjects\Enums\PaymentTransactionType;
use HiEvents\DomainObjects\Generated\OrderPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\OrderPaymentRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\RecordOrderPaymentDTO;
use HiEvents\Services\Domain\Order\ApplyOrderBalanceStatusService;
use HiEvents\Services\Domain\Order\OrderBalanceService;
use HiEvents\Services\Domain\Order\RecordOrderPaymentService;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RecordOrderPaymentServiceTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orderRepository;

    private OrderPaymentRepositoryInterface|MockInterface $orderPaymentRepository;

    private ApplyOrderBalanceStatusService|MockInterface $applyOrderBalanceStatusService;

    private OrderBalanceService|MockInterface $orderBalanceService;

    private RecordOrderPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->orderPaymentRepository = Mockery::mock(OrderPaymentRepositoryInterface::class);
        $this->applyOrderBalanceStatusService = Mockery::mock(ApplyOrderBalanceStatusService::class);
        $this->orderBalanceService = Mockery::mock(OrderBalanceService::class);

        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

        $this->service = new RecordOrderPaymentService(
            $this->orderRepository,
            $this->orderPaymentRepository,
            $this->applyOrderBalanceStatusService,
            $this->orderBalanceService,
            $databaseManager,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_records_ledger_row_and_reconciles_status(): void
    {
        $order = $this->order();

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->once()->andReturn($order);
        $this->orderRepository->shouldReceive('findById')->andReturn($order);

        $this->orderPaymentRepository->shouldReceive('create')->once()
            ->with(Mockery::on(fn (array $a) => $a[OrderPaymentDomainObjectAbstract::ORDER_ID] === 7
                && $a[OrderPaymentDomainObjectAbstract::TRANSACTION_TYPE] === PaymentTransactionType::PAYMENT->value
                && $a[OrderPaymentDomainObjectAbstract::PAYMENT_METHOD] === OfflinePaymentMethod::CASH->value
                && $a[OrderPaymentDomainObjectAbstract::AMOUNT] === 40.0));

        $this->applyOrderBalanceStatusService->shouldReceive('apply')->once()->with($order);

        $result = $this->service->record($this->dto(PaymentTransactionType::PAYMENT, 40.0));

        $this->assertSame($order, $result);
    }

    public function test_splits_overpayment_into_payment_and_donation(): void
    {
        $order = $this->order();

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->once()->andReturn($order);
        $this->orderRepository->shouldReceive('findById')->andReturn($order);

        // Outstanding is 15; a $20 payment overshoots by $5.
        $this->orderBalanceService->shouldReceive('getBalanceForOrder')->once()->with($order)
            ->andReturn($this->balance(15.0));

        $this->orderPaymentRepository->shouldReceive('create')->once()
            ->with(Mockery::on(fn (array $a) => $a[OrderPaymentDomainObjectAbstract::TRANSACTION_TYPE] === PaymentTransactionType::PAYMENT->value
                && $a[OrderPaymentDomainObjectAbstract::PAYMENT_METHOD] === OfflinePaymentMethod::CASH->value
                && $a[OrderPaymentDomainObjectAbstract::AMOUNT] === 15.0));

        $this->orderPaymentRepository->shouldReceive('create')->once()
            ->with(Mockery::on(fn (array $a) => $a[OrderPaymentDomainObjectAbstract::TRANSACTION_TYPE] === PaymentTransactionType::DONATION->value
                && $a[OrderPaymentDomainObjectAbstract::PAYMENT_METHOD] === OfflinePaymentMethod::CASH->value
                && $a[OrderPaymentDomainObjectAbstract::AMOUNT] === 5.0));

        $this->applyOrderBalanceStatusService->shouldReceive('apply')->once()->with($order);

        $this->service->record($this->dto(PaymentTransactionType::PAYMENT, 20.0, splitExcessAsDonation: true));

        $this->addToAssertionCount(1);
    }

    public function test_does_not_split_when_payment_does_not_exceed_balance(): void
    {
        $order = $this->order();

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->once()->andReturn($order);
        $this->orderRepository->shouldReceive('findById')->andReturn($order);

        $this->orderBalanceService->shouldReceive('getBalanceForOrder')->once()->with($order)
            ->andReturn($this->balance(15.0));

        // Exact settlement — a single PAYMENT row, no donation.
        $this->orderPaymentRepository->shouldReceive('create')->once()
            ->with(Mockery::on(fn (array $a) => $a[OrderPaymentDomainObjectAbstract::TRANSACTION_TYPE] === PaymentTransactionType::PAYMENT->value
                && $a[OrderPaymentDomainObjectAbstract::AMOUNT] === 15.0));

        $this->applyOrderBalanceStatusService->shouldReceive('apply')->once()->with($order);

        $this->service->record($this->dto(PaymentTransactionType::PAYMENT, 15.0, splitExcessAsDonation: true));

        $this->addToAssertionCount(1);
    }

    public function test_does_not_split_a_non_payment_transaction(): void
    {
        $order = $this->order();

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->once()->andReturn($order);
        $this->orderRepository->shouldReceive('findById')->andReturn($order);

        // A DONATION is never split, so the balance is never consulted.
        $this->orderBalanceService->shouldNotReceive('getBalanceForOrder');

        $this->orderPaymentRepository->shouldReceive('create')->once()
            ->with(Mockery::on(fn (array $a) => $a[OrderPaymentDomainObjectAbstract::TRANSACTION_TYPE] === PaymentTransactionType::DONATION->value
                && $a[OrderPaymentDomainObjectAbstract::AMOUNT] === 20.0));

        $this->applyOrderBalanceStatusService->shouldReceive('apply')->once()->with($order);

        $this->service->record($this->dto(PaymentTransactionType::DONATION, 20.0, splitExcessAsDonation: true));

        $this->addToAssertionCount(1);
    }

    public function test_throws_when_order_not_found(): void
    {
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->once()->andReturn(null);

        $this->expectException(ResourceNotFoundException::class);

        $this->service->record($this->dto(PaymentTransactionType::PAYMENT, 40.0));
    }

    public function test_throws_when_order_in_uneditable_status(): void
    {
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getStatus')->andReturn(OrderStatus::CANCELLED->name);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')->once()->andReturn($order);

        $this->orderPaymentRepository->shouldNotReceive('create');

        $this->expectException(ResourceConflictException::class);

        $this->service->record($this->dto(PaymentTransactionType::PAYMENT, 40.0));
    }

    private function order(): OrderDomainObject|MockInterface
    {
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(7);
        $order->shouldReceive('getStatus')->andReturn(OrderStatus::AWAITING_OFFLINE_PAYMENT->name);
        $order->shouldReceive('getCurrency')->andReturn('USD');

        return $order;
    }

    private function balance(float $balance): OrderBalanceDTO
    {
        return new OrderBalanceDTO(
            amountOwed: $balance,
            amountCollected: 0.0,
            totalComps: 0.0,
            totalRefunded: 0.0,
            balance: $balance,
            overpaid: 0.0,
            isSettled: $balance <= 0.0,
        );
    }

    private function dto(
        PaymentTransactionType $transactionType,
        float $amount,
        bool $splitExcessAsDonation = false,
    ): RecordOrderPaymentDTO {
        return new RecordOrderPaymentDTO(
            eventId: 3,
            orderId: 7,
            transactionType: $transactionType,
            amount: $amount,
            paymentMethod: OfflinePaymentMethod::CASH,
            reference: 'ref-1',
            note: null,
            recordedByUserId: 9,
            recordedByIp: '127.0.0.1',
            splitExcessAsDonation: $splitExcessAsDonation,
        );
    }
}
