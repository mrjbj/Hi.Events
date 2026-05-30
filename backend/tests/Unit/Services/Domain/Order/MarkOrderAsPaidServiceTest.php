<?php

namespace Tests\Unit\Services\Domain\Order;

use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\OfflinePaymentMethod;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\InvoiceRepositoryInterface;
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

    private DatabaseManager|MockInterface $databaseManager;

    private AffiliateRepositoryInterface|MockInterface $affiliateRepository;

    private InvoiceRepositoryInterface|MockInterface $invoiceRepository;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private DomainEventDispatcherService|MockInterface $domainEventDispatcherService;

    private OrderApplicationFeeCalculationService|MockInterface $orderApplicationFeeCalculationService;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private OrderApplicationFeeService|MockInterface $orderApplicationFeeService;

    private SendOrderDetailsService|MockInterface $sendOrderDetailsService;

    private MarkOrderAsPaidService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->databaseManager = Mockery::mock(DatabaseManager::class);
        $this->affiliateRepository = Mockery::mock(AffiliateRepositoryInterface::class);
        $this->invoiceRepository = Mockery::mock(InvoiceRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->domainEventDispatcherService = Mockery::mock(DomainEventDispatcherService::class);
        $this->orderApplicationFeeCalculationService = Mockery::mock(OrderApplicationFeeCalculationService::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->orderApplicationFeeService = Mockery::mock(OrderApplicationFeeService::class);
        $this->sendOrderDetailsService = Mockery::mock(SendOrderDetailsService::class);

        $this->databaseManager->shouldReceive('transaction')
            ->andReturnUsing(fn ($callback) => $callback());

        $this->service = new MarkOrderAsPaidService(
            $this->orderRepository,
            $this->databaseManager,
            $this->affiliateRepository,
            $this->invoiceRepository,
            $this->attendeeRepository,
            $this->domainEventDispatcherService,
            $this->orderApplicationFeeCalculationService,
            $this->eventRepository,
            $this->orderApplicationFeeService,
            $this->sendOrderDetailsService,
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

    public function test_marks_order_as_paid_without_touching_totals(): void
    {
        $this->setupInitialOrderLookup(
            orderStatus: OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
            totalGross: 25.0,
            items: collect([$this->mockItem(1, 25.0)]),
        );
        $this->setupSideEffectMocks(updatedOrderTotalGross: 25.0);

        $this->orderRepository
            ->shouldReceive('updateFromArray')
            ->once()
            ->with(99, Mockery::on(function (array $attrs) {
                return $attrs[OrderDomainObjectAbstract::STATUS] === OrderStatus::COMPLETED->name
                    && $attrs[OrderDomainObjectAbstract::OFFLINE_PAYMENT_METHOD] === 'CASH'
                    && $attrs[OrderDomainObjectAbstract::OFFLINE_PAYMENT_REFERENCE] === 'check-ref-1'
                    && !array_key_exists(OrderDomainObjectAbstract::TOTAL_GROSS, $attrs);
            }));

        $this->service->markOrderAsPaid($this->buildDto(
            paymentMethod: OfflinePaymentMethod::CASH,
            paymentReference: 'check-ref-1',
        ));

        $this->addToAssertionCount(1);
    }

    // ----- helpers -----

    private function buildDto(
        OfflinePaymentMethod $paymentMethod = OfflinePaymentMethod::CASH,
        ?string $paymentReference = null,
    ): MarkOrderAsPaidDTO {
        return new MarkOrderAsPaidDTO(
            eventId: 10,
            orderId: 99,
            paymentMethod: $paymentMethod,
            paymentReference: $paymentReference,
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
