<?php

namespace Tests\Unit\Services\Application\Handlers\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\TaxAndFeeRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\CreateAttendeeHandler;
use HiEvents\Services\Application\Handlers\Attendee\DTO\CreateAttendeeDTO;
use HiEvents\Services\Domain\Order\OrderManagementService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Domain\Tax\TaxAndFeeRollupService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateAttendeeHandlerTest extends TestCase
{
    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private OrderRepositoryInterface|MockInterface $orderRepository;

    private ProductRepositoryInterface|MockInterface $productRepository;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private ProductQuantityUpdateService|MockInterface $productQuantityAdjustmentService;

    private DatabaseManager|MockInterface $databaseManager;

    private TaxAndFeeRepositoryInterface|MockInterface $taxAndFeeRepository;

    private TaxAndFeeRollupService|MockInterface $taxAndFeeRollupService;

    private OrderManagementService|MockInterface $orderManagementService;

    private DomainEventDispatcherService|MockInterface $domainEventDispatcherService;

    private EventSettingsRepositoryInterface|MockInterface $eventSettingsRepository;

    private CreateAttendeeHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->productQuantityAdjustmentService = Mockery::mock(ProductQuantityUpdateService::class);
        $this->databaseManager = Mockery::mock(DatabaseManager::class);
        $this->taxAndFeeRepository = Mockery::mock(TaxAndFeeRepositoryInterface::class);
        $this->taxAndFeeRollupService = Mockery::mock(TaxAndFeeRollupService::class);
        $this->orderManagementService = Mockery::mock(OrderManagementService::class);
        $this->domainEventDispatcherService = Mockery::mock(DomainEventDispatcherService::class);
        $this->eventSettingsRepository = Mockery::mock(EventSettingsRepositoryInterface::class);

        $this->databaseManager->shouldReceive('transaction')
            ->andReturnUsing(fn ($callback) => $callback());

        $this->handler = new CreateAttendeeHandler(
            $this->attendeeRepository,
            $this->orderRepository,
            $this->productRepository,
            $this->eventRepository,
            $this->productQuantityAdjustmentService,
            $this->databaseManager,
            $this->taxAndFeeRepository,
            $this->taxAndFeeRollupService,
            $this->orderManagementService,
            $this->domainEventDispatcherService,
            $this->eventSettingsRepository,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_offline_payment_throws_when_allow_check_in_setting_disabled(): void
    {
        $dto = $this->buildDto(requiresOfflinePayment: true);

        $this->eventSettingsRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['event_id' => 10])
            ->andReturn($this->buildEventSettings(allowCheckIn: false));

        $this->expectException(ResourceConflictException::class);
        $this->expectExceptionMessage('Allow attendees associated with unpaid orders');

        $this->handler->handle($dto);
    }

    public function test_offline_payment_creates_order_awaiting_offline_payment_and_attendee_awaiting_payment(): void
    {
        $dto = $this->buildDto(requiresOfflinePayment: true);

        $this->eventSettingsRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($this->buildEventSettings(allowCheckIn: true));

        $capturedOrderAttributes = null;
        $capturedAttendeeAttributes = null;

        $this->setupHappyPathMocks(
            $dto,
            orderCapture: function (array $attrs) use (&$capturedOrderAttributes) {
                $capturedOrderAttributes = $attrs;
            },
            attendeeCapture: function (array $attrs) use (&$capturedAttendeeAttributes) {
                $capturedAttendeeAttributes = $attrs;
            },
        );

        $this->handler->handle($dto);

        $this->assertSame(
            OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
            $capturedOrderAttributes[OrderDomainObjectAbstract::STATUS],
        );
        $this->assertSame(
            OrderPaymentStatus::AWAITING_OFFLINE_PAYMENT->name,
            $capturedOrderAttributes[OrderDomainObjectAbstract::PAYMENT_STATUS],
        );
        $this->assertSame(
            PaymentProviders::OFFLINE->value,
            $capturedOrderAttributes[OrderDomainObjectAbstract::PAYMENT_PROVIDER],
        );
        $this->assertSame(
            AttendeeStatus::AWAITING_PAYMENT->name,
            $capturedAttendeeAttributes[AttendeeDomainObjectAbstract::STATUS],
        );
    }

    public function test_default_flow_creates_completed_order_and_active_attendee(): void
    {
        $dto = $this->buildDto(requiresOfflinePayment: false, amountPaid: 25.0);

        $this->eventSettingsRepository->shouldNotReceive('findFirstWhere');

        $capturedOrderAttributes = null;
        $capturedAttendeeAttributes = null;

        $this->setupHappyPathMocks(
            $dto,
            orderCapture: function (array $attrs) use (&$capturedOrderAttributes) {
                $capturedOrderAttributes = $attrs;
            },
            attendeeCapture: function (array $attrs) use (&$capturedAttendeeAttributes) {
                $capturedAttendeeAttributes = $attrs;
            },
        );

        $this->handler->handle($dto);

        $this->assertSame(
            OrderStatus::COMPLETED->name,
            $capturedOrderAttributes[OrderDomainObjectAbstract::STATUS],
        );
        $this->assertSame(
            OrderPaymentStatus::PAYMENT_RECEIVED->name,
            $capturedOrderAttributes[OrderDomainObjectAbstract::PAYMENT_STATUS],
        );
        $this->assertArrayNotHasKey(
            OrderDomainObjectAbstract::PAYMENT_PROVIDER,
            $capturedOrderAttributes,
        );
        $this->assertSame(
            AttendeeStatus::ACTIVE->name,
            $capturedAttendeeAttributes[AttendeeDomainObjectAbstract::STATUS],
        );
    }

    private function buildDto(bool $requiresOfflinePayment = false, float $amountPaid = 0.0): CreateAttendeeDTO
    {
        return new CreateAttendeeDTO(
            first_name: 'Test',
            last_name: 'User',
            email: 'test@example.com',
            product_id: 50,
            event_id: 10,
            send_confirmation_email: true,
            amount_paid: $amountPaid,
            locale: 'en',
            amount_includes_tax: false,
            product_price_id: 100,
            taxes_and_fees: null,
            requires_offline_payment: $requiresOfflinePayment,
        );
    }

    private function buildEventSettings(bool $allowCheckIn): EventSettingDomainObject
    {
        $settings = new EventSettingDomainObject;
        $settings->setAllowOrdersAwaitingOfflinePaymentToCheckIn($allowCheckIn);

        return $settings;
    }

    private function setupHappyPathMocks(CreateAttendeeDTO $dto, callable $orderCapture, callable $attendeeCapture): void
    {
        $event = Mockery::mock(EventDomainObject::class);
        $event->shouldReceive('getCurrency')->andReturn('USD');

        $this->eventRepository
            ->shouldReceive('findById')
            ->with($dto->event_id)
            ->andReturn($event);

        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(999);
        $order->shouldReceive('getEventId')->andReturn($dto->event_id);

        $this->orderRepository
            ->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (array $attrs) use ($order, $orderCapture) {
                $orderCapture($attrs);

                return $order;
            });

        $productPrice = Mockery::mock(ProductPriceDomainObject::class);
        $productPrice->shouldReceive('getId')->andReturn($dto->product_price_id);

        $product = Mockery::mock(ProductDomainObject::class);
        $product->shouldReceive('getProductPrices')->andReturn(new Collection([$productPrice]));
        $product->shouldReceive('getTitle')->andReturn('Test Ticket');

        $this->productRepository
            ->shouldReceive('loadRelation')
            ->with(ProductPriceDomainObject::class)
            ->andReturnSelf();
        $this->productRepository
            ->shouldReceive('findFirstWhere')
            ->andReturn($product);
        $this->productRepository
            ->shouldReceive('getQuantityRemainingForProductPrice')
            ->andReturn(10);

        $this->taxAndFeeRollupService->shouldReceive('getTotalTaxesAndFees')->andReturn(0.0);
        $this->taxAndFeeRollupService->shouldReceive('getTotalTaxes')->andReturn(0.0);
        $this->taxAndFeeRollupService->shouldReceive('getTotalFees')->andReturn(0.0);
        $this->taxAndFeeRollupService->shouldReceive('getRollUp')->andReturn([]);

        $orderItem = Mockery::mock(OrderItemDomainObject::class);
        $this->orderRepository
            ->shouldReceive('addOrderItem')
            ->andReturn($orderItem);

        $attendee = Mockery::mock(AttendeeDomainObject::class);
        $this->attendeeRepository
            ->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (array $attrs) use ($attendee, $attendeeCapture) {
                $attendeeCapture($attrs);

                return $attendee;
            });

        $this->orderManagementService
            ->shouldReceive('updateOrderTotals')
            ->once();

        $this->productQuantityAdjustmentService
            ->shouldReceive('increaseQuantitySold')
            ->once();

        $this->domainEventDispatcherService
            ->shouldReceive('dispatch')
            ->once();
    }
}
