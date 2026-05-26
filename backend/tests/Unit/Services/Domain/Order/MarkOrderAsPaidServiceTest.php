<?php

namespace Tests\Unit\Services\Domain\Order;

use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\OfflinePaymentMethod;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderItemDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderPaymentAdjustmentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrderPaymentAdjustmentDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\InvoiceRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderItemRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderPaymentAdjustmentRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\MarkOrderAsPaidDTO;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use HiEvents\Services\Domain\Order\MarkOrderAsPaidService;
use HiEvents\Services\Domain\Order\OrderApplicationFeeCalculationService;
use HiEvents\Services\Domain\Order\OrderApplicationFeeService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class MarkOrderAsPaidServiceTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orderRepository;

    private OrderItemRepositoryInterface|MockInterface $orderItemRepository;

    private DatabaseManager|MockInterface $databaseManager;

    private AffiliateRepositoryInterface|MockInterface $affiliateRepository;

    private InvoiceRepositoryInterface|MockInterface $invoiceRepository;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private DomainEventDispatcherService|MockInterface $domainEventDispatcherService;

    private OrderApplicationFeeCalculationService|MockInterface $orderApplicationFeeCalculationService;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private OrderApplicationFeeService|MockInterface $orderApplicationFeeService;

    private SendOrderDetailsService|MockInterface $sendOrderDetailsService;

    private OrderPaymentAdjustmentRepositoryInterface|MockInterface $orderPaymentAdjustmentRepository;

    private MarkOrderAsPaidService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->orderItemRepository = Mockery::mock(OrderItemRepositoryInterface::class);
        $this->databaseManager = Mockery::mock(DatabaseManager::class);
        $this->affiliateRepository = Mockery::mock(AffiliateRepositoryInterface::class);
        $this->invoiceRepository = Mockery::mock(InvoiceRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->domainEventDispatcherService = Mockery::mock(DomainEventDispatcherService::class);
        $this->orderApplicationFeeCalculationService = Mockery::mock(OrderApplicationFeeCalculationService::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->orderApplicationFeeService = Mockery::mock(OrderApplicationFeeService::class);
        $this->sendOrderDetailsService = Mockery::mock(SendOrderDetailsService::class);
        $this->orderPaymentAdjustmentRepository = Mockery::mock(OrderPaymentAdjustmentRepositoryInterface::class);

        $this->databaseManager->shouldReceive('transaction')
            ->andReturnUsing(fn ($callback) => $callback());

        $this->service = new MarkOrderAsPaidService(
            $this->orderRepository,
            $this->orderItemRepository,
            $this->databaseManager,
            $this->affiliateRepository,
            $this->invoiceRepository,
            $this->attendeeRepository,
            $this->domainEventDispatcherService,
            $this->orderApplicationFeeCalculationService,
            $this->eventRepository,
            $this->orderApplicationFeeService,
            $this->sendOrderDetailsService,
            $this->orderPaymentAdjustmentRepository,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_throws_when_order_not_awaiting_offline_payment(): void
    {
        $this->setupInitialOrderLookup(
            orderStatus: OrderStatus::COMPLETED->name,
            totalGross: 25.0,
            items: collect(),
        );

        $this->expectException(ResourceConflictException::class);
        $this->expectExceptionMessage('Order is not awaiting offline payment');

        $this->service->markOrderAsPaid($this->buildDto());
    }

    public function test_records_method_when_no_amount_override(): void
    {
        $this->setupInitialOrderLookup(
            orderStatus: OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
            totalGross: 25.0,
            items: collect([$this->mockItem(1, 25.0)]),
        );
        $this->setupSideEffectMocks(updatedOrderTotalGross: 25.0);

        $this->orderPaymentAdjustmentRepository->shouldNotReceive('create');
        $this->orderItemRepository->shouldNotReceive('updateFromArray');

        $this->orderRepository
            ->shouldReceive('updateFromArray')
            ->once()
            ->with(99, Mockery::on(function (array $attrs) {
                return $attrs[OrderDomainObjectAbstract::STATUS] === OrderStatus::COMPLETED->name
                    && $attrs[OrderDomainObjectAbstract::OFFLINE_PAYMENT_METHOD] === 'CASH'
                    && $attrs[OrderDomainObjectAbstract::OFFLINE_PAYMENT_REFERENCE] === 'check-ref-1';
            }));

        $this->service->markOrderAsPaid($this->buildDto(
            paymentMethod: OfflinePaymentMethod::CASH,
            paymentReference: 'check-ref-1',
            collectedAmount: null,
        ));

        $this->addToAssertionCount(1);
    }

    public function test_no_audit_row_when_collected_equals_total(): void
    {
        $this->setupInitialOrderLookup(
            orderStatus: OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
            totalGross: 25.0,
            items: collect([$this->mockItem(1, 25.0)]),
        );
        $this->setupSideEffectMocks(updatedOrderTotalGross: 25.0);

        $this->orderPaymentAdjustmentRepository->shouldNotReceive('create');
        $this->orderItemRepository->shouldNotReceive('updateFromArray');

        $this->orderRepository
            ->shouldReceive('updateFromArray')
            ->once()
            ->andReturn(new OrderDomainObject());

        $this->service->markOrderAsPaid($this->buildDto(collectedAmount: 25.0));

        $this->addToAssertionCount(1);
    }

    public function test_writes_audit_row_and_zeroes_taxes_when_amount_overridden(): void
    {
        $this->setupInitialOrderLookup(
            orderStatus: OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
            totalGross: 25.0,
            totalBeforeAdditions: 22.94,
            totalTax: 2.06,
            totalFee: 0.0,
            items: collect([$this->mockItem(1, 25.0)]),
        );
        $this->setupSideEffectMocks(updatedOrderTotalGross: 20.0);

        $capturedAdjustment = null;
        $this->orderPaymentAdjustmentRepository
            ->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (array $attrs) use (&$capturedAdjustment) {
                $capturedAdjustment = $attrs;
                return new OrderPaymentAdjustmentDomainObject();
            });

        $capturedItemUpdate = null;
        $this->orderItemRepository
            ->shouldReceive('updateFromArray')
            ->once()
            ->andReturnUsing(function (int $id, array $attrs) use (&$capturedItemUpdate) {
                $capturedItemUpdate = $attrs;
                return new OrderItemDomainObject();
            });

        $orderUpdates = [];
        $this->orderRepository
            ->shouldReceive('updateFromArray')
            ->twice()
            ->andReturnUsing(function (int $id, array $attrs) use (&$orderUpdates) {
                $orderUpdates[] = $attrs;
                return new OrderDomainObject();
            });

        $this->service->markOrderAsPaid($this->buildDto(
            paymentMethod: OfflinePaymentMethod::CASH,
            paymentReference: null,
            collectedAmount: 20.0,
        ));

        $this->assertSame(25.0, $capturedAdjustment[OrderPaymentAdjustmentDomainObjectAbstract::ORIGINAL_TOTAL_GROSS]);
        $this->assertSame(20.0, $capturedAdjustment[OrderPaymentAdjustmentDomainObjectAbstract::ADJUSTED_TOTAL_GROSS]);
        $this->assertSame('CASH', $capturedAdjustment[OrderPaymentAdjustmentDomainObjectAbstract::PAYMENT_METHOD]);

        $this->assertSame(20.0, $orderUpdates[0][OrderDomainObjectAbstract::TOTAL_GROSS]);
        $this->assertSame(20.0, $orderUpdates[0][OrderDomainObjectAbstract::TOTAL_BEFORE_ADDITIONS]);
        $this->assertSame(0, $orderUpdates[0][OrderDomainObjectAbstract::TOTAL_TAX]);
        $this->assertSame(0, $orderUpdates[0][OrderDomainObjectAbstract::TOTAL_FEE]);

        $this->assertSame(20.0, $capturedItemUpdate[OrderItemDomainObjectAbstract::PRICE]);
        $this->assertSame(20.0, $capturedItemUpdate[OrderItemDomainObjectAbstract::TOTAL_GROSS]);
        $this->assertSame(0, $capturedItemUpdate[OrderItemDomainObjectAbstract::TOTAL_TAX]);
    }

    public function test_multi_item_proportional_scaling_with_remainder_to_last_item(): void
    {
        $this->setupInitialOrderLookup(
            orderStatus: OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
            totalGross: 100.0,
            items: collect([
                $this->mockItem(11, 30.0),
                $this->mockItem(12, 30.0),
                $this->mockItem(13, 40.0),
            ]),
        );
        $this->setupSideEffectMocks(updatedOrderTotalGross: 80.0);
        $this->orderPaymentAdjustmentRepository->shouldReceive('create')->once();
        $this->orderRepository
            ->shouldReceive('updateFromArray')
            ->twice()
            ->andReturn(new OrderDomainObject());

        $itemUpdates = [];
        $this->orderItemRepository
            ->shouldReceive('updateFromArray')
            ->times(3)
            ->andReturnUsing(function (int $id, array $attrs) use (&$itemUpdates) {
                $itemUpdates[$id] = $attrs[OrderItemDomainObjectAbstract::PRICE];
                return new OrderItemDomainObject();
            });

        $this->service->markOrderAsPaid($this->buildDto(collectedAmount: 80.0));

        // 80/100 ratio: 30→24, 30→24, last item gets remainder 80-48 = 32
        $this->assertSame(24.0, $itemUpdates[11]);
        $this->assertSame(24.0, $itemUpdates[12]);
        $this->assertSame(32.0, $itemUpdates[13]);
        $this->assertEqualsWithDelta(80.0, array_sum($itemUpdates), 0.001);
    }

    // ----- helpers -----

    private function buildDto(
        OfflinePaymentMethod $paymentMethod = OfflinePaymentMethod::CASH,
        ?string $paymentReference = null,
        ?float $collectedAmount = null,
    ): MarkOrderAsPaidDTO {
        return new MarkOrderAsPaidDTO(
            eventId: 10,
            orderId: 99,
            paymentMethod: $paymentMethod,
            paymentReference: $paymentReference,
            collectedAmount: $collectedAmount,
        );
    }

    private function mockItem(int $id, float $totalGross): OrderItemDomainObject
    {
        $item = Mockery::mock(OrderItemDomainObject::class);
        $item->shouldReceive('getId')->andReturn($id);
        $item->shouldReceive('getTotalGross')->andReturn($totalGross);
        return $item;
    }

    private function setupInitialOrderLookup(
        string $orderStatus,
        float $totalGross,
        Collection $items,
        float $totalBeforeAdditions = 0.0,
        float $totalTax = 0.0,
        float $totalFee = 0.0,
    ): void {
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(99);
        $order->shouldReceive('getStatus')->andReturn($orderStatus);
        $order->shouldReceive('getEventId')->andReturn(10);
        $order->shouldReceive('getTotalGross')->andReturn($totalGross);
        $order->shouldReceive('getTotalBeforeAdditions')->andReturn($totalBeforeAdditions);
        $order->shouldReceive('getTotalTax')->andReturn($totalTax);
        $order->shouldReceive('getTotalFee')->andReturn($totalFee);
        $order->shouldReceive('getOrderItems')->andReturn($items);
        $order->shouldReceive('getPaymentProvider')->andReturn(null);
        $order->shouldReceive('getLatestInvoice')->andReturn(null);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($order);

        $account = Mockery::mock(AccountDomainObject::class);
        $config = Mockery::mock(AccountConfigurationDomainObject::class);
        $account->shouldReceive('getConfiguration')->andReturn($config);

        $event = Mockery::mock(EventDomainObject::class);
        $event->shouldReceive('getOrganizer')->andReturn(Mockery::mock(OrganizerDomainObject::class));
        $event->shouldReceive('getEventSettings')->andReturn(Mockery::mock(EventSettingDomainObject::class));
        $event->shouldReceive('getAccount')->andReturn($account);

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository
            ->shouldReceive('findById')
            ->andReturn($event);
    }

    private function setupSideEffectMocks(float $updatedOrderTotalGross): void
    {
        $updatedOrder = Mockery::mock(OrderDomainObject::class);
        $updatedOrder->shouldReceive('getId')->andReturn(99);
        $updatedOrder->shouldReceive('getEventId')->andReturn(10);
        $updatedOrder->shouldReceive('getAffiliateId')->andReturn(null);
        $updatedOrder->shouldReceive('getTotalGross')->andReturn($updatedOrderTotalGross);
        $updatedOrder->shouldReceive('getCurrency')->andReturn('USD');

        $this->orderRepository
            ->shouldReceive('findById')
            ->andReturn($updatedOrder);

        $this->invoiceRepository->shouldReceive('findLatestInvoiceForOrder')->andReturn(null);

        $this->attendeeRepository->shouldReceive('updateWhere');

        $this->domainEventDispatcherService->shouldReceive('dispatch')->once();

        $this->orderApplicationFeeCalculationService
            ->shouldReceive('calculateApplicationFee')
            ->andReturn(null);

        $this->orderApplicationFeeService->shouldReceive('createOrderApplicationFee')->once();

        $this->sendOrderDetailsService->shouldReceive('sendCustomerOrderSummary')->once();
    }
}
