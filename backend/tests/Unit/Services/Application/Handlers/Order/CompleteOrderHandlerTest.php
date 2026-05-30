<?php

namespace Tests\Unit\Services\Application\Handlers\Order;

use Carbon\Carbon;
use Exception;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\AttendeeDetailsCollectionMethod;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\CompleteOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CompleteOrderDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\CompleteOrderOrderDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\CompleteOrderProductDataDTO;
use HiEvents\Services\Domain\Contact\ContactAutofillService;
use HiEvents\Services\Domain\Contact\ContactBackfillService;
use HiEvents\Services\Domain\Contact\ContactUpsertService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class CompleteOrderHandlerTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orderRepository;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private QuestionAnswerRepositoryInterface|MockInterface $questionAnswersRepository;

    private ProductQuantityUpdateService|MockInterface $productQuantityUpdateService;

    private ProductPriceRepositoryInterface|MockInterface $productPriceRepository;

    private CompleteOrderHandler $completeOrderHandler;

    private DomainEventDispatcherService $domainEventDispatcherService;

    private AffiliateRepositoryInterface|MockInterface $affiliateRepository;

    private EventSettingsRepositoryInterface $eventSettingsRepository;

    private CheckoutSessionManagementService|MockInterface $sessionManagementService;

    private ContactUpsertService|MockInterface $contactUpsertService;

    private ContactBackfillService|MockInterface $contactBackfillService;

    private ContactAutofillService|MockInterface $contactAutofillService;

    private EventRepositoryInterface|MockInterface $eventRepository;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Bus::fake();
        DB::shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback(Mockery::mock(Connection::class)));

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->questionAnswersRepository = Mockery::mock(QuestionAnswerRepositoryInterface::class);
        $this->productQuantityUpdateService = Mockery::mock(ProductQuantityUpdateService::class);
        $this->productPriceRepository = Mockery::mock(ProductPriceRepositoryInterface::class);
        $this->domainEventDispatcherService = Mockery::mock(DomainEventDispatcherService::class);
        $this->affiliateRepository = Mockery::mock(AffiliateRepositoryInterface::class);
        $this->eventSettingsRepository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $this->sessionManagementService = Mockery::mock(CheckoutSessionManagementService::class);
        $this->sessionManagementService->shouldReceive('verifySession')->andReturn(true)->byDefault();
        $this->contactUpsertService = Mockery::mock(ContactUpsertService::class)->shouldIgnoreMissing();
        $this->contactBackfillService = Mockery::mock(ContactBackfillService::class)->shouldIgnoreMissing();
        $this->contactAutofillService = Mockery::mock(ContactAutofillService::class)->shouldIgnoreMissing();
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class)->shouldIgnoreMissing();

        $this->completeOrderHandler = new CompleteOrderHandler(
            $this->orderRepository,
            $this->affiliateRepository,
            $this->attendeeRepository,
            $this->questionAnswersRepository,
            $this->productQuantityUpdateService,
            $this->productPriceRepository,
            $this->domainEventDispatcherService,
            $this->eventSettingsRepository,
            $this->sessionManagementService,
            $this->contactUpsertService,
            $this->contactBackfillService,
            $this->contactAutofillService,
            $this->eventRepository,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_successfully_completes_order(): void
    {
        $orderShortId = 'ABC123';
        $orderData = $this->createMockCompleteOrderDTO();
        $order = $this->createMockOrder();
        $updatedOrder = $this->createMockOrder();

        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($updatedOrder);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();

        $this->productPriceRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockProductPrice()]));

        $this->attendeeRepository->shouldReceive('insert')->andReturn(true);
        $this->attendeeRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockAttendee()]));

        $this->productQuantityUpdateService->shouldReceive('updateQuantitiesFromOrder');

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());

        $this->completeOrderHandler->handle($orderShortId, $orderData);

        $this->assertTrue(true);
    }

    public function test_bundle_sponsor_seat_links_while_blank_guests_wait_for_checkin(): void
    {
        $orderShortId = 'ABC123';

        // "Copy to all" half-table: the first seat carries the buyer's name +
        // email (the sponsor); siblings get "Guest N" + a BLANK email. The
        // buyer's email is never shared onto another seat.
        $orderDTO = new CompleteOrderOrderDTO(
            first_name: 'Pat',
            last_name: 'Sponsor',
            email: 'pat@example.com',
            questions: null,
        );
        $products = new Collection([
            new CompleteOrderProductDataDTO(product_price_id: 1, first_name: 'Pat', last_name: 'Sponsor', email: 'pat@example.com'),
            new CompleteOrderProductDataDTO(product_price_id: 1, first_name: 'Guest 2', last_name: '', email: ''),
            new CompleteOrderProductDataDTO(product_price_id: 1, first_name: 'Guest 3', last_name: '', email: ''),
        ]);

        $captured = $this->captureBundleInserts($orderShortId, $orderDTO, $products);

        $this->assertCount(3, $captured);
        $sponsor = collect($captured)->firstWhere('first_name', 'Pat');
        $this->assertFalse($sponsor['confirm_at_checkin'], 'The sponsor (buyer) seat links immediately');
        $this->assertSame('pat@example.com', $sponsor['email']);
        foreach (collect($captured)->where('first_name', '!=', 'Pat') as $guest) {
            $this->assertTrue($guest['confirm_at_checkin'], 'Blank-email guest seats wait for check-in confirmation');
            $this->assertNull($guest['email'], 'Blank-email guests keep a null email (no buyer fallback)');
        }
    }

    public function test_bundle_with_distinct_emails_links_every_seat(): void
    {
        $orderShortId = 'ABC123';

        // A table the buyer registers for others, each with their own distinct
        // email: every seat has an identity, so every seat links immediately.
        $orderDTO = new CompleteOrderOrderDTO(
            first_name: 'Pat',
            last_name: 'Sponsor',
            email: 'pat@example.com',
            questions: null,
        );
        $products = new Collection([
            new CompleteOrderProductDataDTO(product_price_id: 1, first_name: 'Alex', last_name: 'Guest', email: 'alex@example.com'),
            new CompleteOrderProductDataDTO(product_price_id: 1, first_name: 'Sam', last_name: 'Guest', email: 'sam@example.com'),
        ]);

        $captured = $this->captureBundleInserts($orderShortId, $orderDTO, $products, quantity: 2);

        $this->assertCount(2, $captured);
        foreach ($captured as $insert) {
            $this->assertFalse($insert['confirm_at_checkin'], 'A seat with its own email links immediately');
            $this->assertNotNull($insert['email']);
        }
    }

    public function test_bundle_mixed_blank_and_emailed_seats(): void
    {
        $orderShortId = 'ABC123';

        $orderDTO = new CompleteOrderOrderDTO(
            first_name: 'Pat',
            last_name: 'Sponsor',
            email: 'pat@example.com',
            questions: null,
        );
        // One seat with its own email links; one blank seat waits.
        $products = new Collection([
            new CompleteOrderProductDataDTO(product_price_id: 1, first_name: 'Alex', last_name: 'Guest', email: 'alex@example.com'),
            new CompleteOrderProductDataDTO(product_price_id: 1, first_name: 'Guest 2', last_name: '', email: ''),
        ]);

        $captured = $this->captureBundleInserts($orderShortId, $orderDTO, $products, quantity: 2);

        $alex = collect($captured)->firstWhere('first_name', 'Alex');
        $guest = collect($captured)->firstWhere('first_name', 'Guest 2');
        $this->assertFalse($alex['confirm_at_checkin']);
        $this->assertSame('alex@example.com', $alex['email']);
        $this->assertTrue($guest['confirm_at_checkin']);
        $this->assertNull($guest['email']);
    }

    public function test_per_order_collection_uses_buyer_identity_for_every_seat(): void
    {
        $orderShortId = 'ABC123';

        $orderDTO = new CompleteOrderOrderDTO(
            first_name: 'Pat',
            last_name: 'Buyer',
            email: 'pat@example.com',
            questions: null,
        );
        // PER_ORDER: attendee rows carry no per-seat identity; all collapse onto
        // the buyer. No seat waits and no uniqueness rejection.
        $products = new Collection([
            new CompleteOrderProductDataDTO(product_price_id: 1, first_name: '', last_name: '', email: ''),
            new CompleteOrderProductDataDTO(product_price_id: 1, first_name: '', last_name: '', email: ''),
        ]);

        $perOrderSetting = $this->createMockEventSetting()
            ->setAttendeeDetailsCollectionMethod(AttendeeDetailsCollectionMethod::PER_ORDER->name);

        $captured = $this->captureBundleInserts($orderShortId, $orderDTO, $products, quantity: 2, eventSetting: $perOrderSetting);

        $this->assertCount(2, $captured);
        foreach ($captured as $insert) {
            $this->assertFalse($insert['confirm_at_checkin'], 'PER_ORDER seats never wait');
            $this->assertSame('pat@example.com', $insert['email'], 'PER_ORDER seats use the buyer email');
        }
    }

    private function captureBundleInserts(
        string $orderShortId,
        CompleteOrderOrderDTO $orderDTO,
        Collection $products,
        int $quantity = 3,
        ?EventSettingDomainObject $eventSetting = null,
    ): array {
        $orderData = new CompleteOrderDTO(order: $orderDTO, products: $products, event_id: 1);

        $order = $this->createMockOrder()->setOrderItems(new Collection([
            (new OrderItemDomainObject)
                ->setId(1)->setProductId(1)->setProductPriceId(1)
                ->setQuantity($quantity)->setPrice(10)->setTotalGross(10 * $quantity)
                ->setProductType(ProductType::TICKET->name),
        ]));

        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($this->createMockOrder());
        $this->productPriceRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockProductPrice()]));
        $this->productQuantityUpdateService->shouldReceive('updateQuantitiesFromOrder');
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($eventSetting ?? $this->createMockEventSetting());
        $this->attendeeRepository->shouldReceive('findWhereIn')->andReturn(new Collection);
        $this->attendeeRepository->shouldReceive('findWhere')->andReturn(new Collection);

        $captured = [];
        $this->attendeeRepository
            ->shouldReceive('insert')
            ->once()
            ->withArgs(function ($inserts) use (&$captured) {
                $captured = $inserts;

                return true;
            })
            ->andReturn(true);

        $this->completeOrderHandler->handle($orderShortId, $orderData);

        return $captured;
    }

    public function test_handle_throws_resource_not_found_exception_when_order_not_found(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $orderShortId = 'NONEXISTENT';
        $orderData = $this->createMockCompleteOrderDTO();

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());
        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturnNull();
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();

        $this->completeOrderHandler->handle($orderShortId, $orderData);
    }

    public function test_handle_throws_resource_conflict_exception_when_order_already_processed(): void
    {
        $this->expectException(ResourceConflictException::class);
        $this->expectExceptionMessage('This order has already been processed');

        $orderShortId = 'ABC123';
        $orderData = $this->createMockCompleteOrderDTO();

        $order = $this->createMockOrder(OrderStatus::COMPLETED);
        $order->setEmail('d@d.com');
        $order->setTotalGross(0);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());
        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();

        $this->completeOrderHandler->handle($orderShortId, $orderData);
    }

    public function test_handle_throws_resource_conflict_exception_when_order_expired(): void
    {
        $this->expectException(ResourceConflictException::class);

        $orderShortId = 'ABC123';
        $orderData = $this->createMockCompleteOrderDTO();
        $order = $this->createMockOrder();
        $order->setEmail('d@d.com');
        $order->setReservedUntil(Carbon::now()->subHour()->toDateTimeString());
        $order->setTotalGross(100);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());
        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();

        $this->completeOrderHandler->handle($orderShortId, $orderData);
    }

    public function test_handle_updates_product_quantities_for_free_order(): void
    {
        Event::fake();

        $orderShortId = 'ABC123';
        $orderData = $this->createMockCompleteOrderDTO();
        $order = $this->createMockOrder();
        $updatedOrder = $this->createMockOrder(OrderStatus::COMPLETED);

        $order->setTotalGross(0);
        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($updatedOrder);

        $this->productPriceRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockProductPrice()]));

        $this->attendeeRepository->shouldReceive('insert')->andReturn(true);
        $this->attendeeRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockAttendee()]));

        $this->productQuantityUpdateService->shouldReceive('updateQuantitiesFromOrder')->once();

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());

        $this->domainEventDispatcherService->shouldReceive('dispatch')
            ->withArgs(function (OrderEvent $event) use ($order) {
                return $event->type === DomainEventType::ORDER_CREATED
                    && $event->orderId === $order->getId();
            })
            ->once();

        $order = $this->completeOrderHandler->handle($orderShortId, $orderData);

        $this->assertSame($order->getStatus(), OrderStatus::COMPLETED->name);
    }

    public function test_handle_does_not_update_product_quantities_for_paid_order(): void
    {
        $orderShortId = 'ABC123';
        $orderData = $this->createMockCompleteOrderDTO();
        $order = $this->createMockOrder();
        $updatedOrder = $this->createMockOrder();

        $order->setTotalGross(10);

        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($updatedOrder);

        $this->productPriceRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockProductPrice()]));

        $this->attendeeRepository->shouldReceive('insert')->andReturn(true);
        $this->attendeeRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockAttendee()]));

        $this->productQuantityUpdateService->shouldNotReceive('updateQuantitiesFromOrder');

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());

        $this->completeOrderHandler->handle($orderShortId, $orderData);

        $this->expectNotToPerformAssertions();
    }

    public function test_handle_throws_exception_when_attendee_insert_fails(): void
    {
        $this->expectException(Exception::class);

        $orderShortId = 'ABC123';
        $orderData = $this->createMockCompleteOrderDTO();
        $order = $this->createMockOrder();
        $updatedOrder = $this->createMockOrder();

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());
        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($updatedOrder);

        $this->productPriceRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockProductPrice()]));

        $this->attendeeRepository->shouldReceive('insert')->andReturn(false);

        $this->completeOrderHandler->handle($orderShortId, $orderData);
    }

    public function test_exception_is_throw_when_attendee_count_does_not_match_order_items_count(): void
    {
        $this->expectException(ResourceConflictException::class);
        $this->expectExceptionMessage('The number of attendees does not match the number of tickets in the order');

        $orderShortId = 'ABC123';
        $orderData = $this->createMockCompleteOrderDTO();
        $order = $this->createMockOrder();
        $updatedOrder = $this->createMockOrder();

        $order->getOrderItems()->first()->setQuantity(2);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn($this->createMockEventSetting());
        $this->orderRepository->shouldReceive('findByShortId')->with($orderShortId)->andReturn($order);
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($updatedOrder);

        $this->productPriceRepository->shouldReceive('findWhereIn')->andReturn(new Collection([$this->createMockProductPrice()]));

        $this->attendeeRepository->shouldReceive('insert')->andReturn(true);
        $this->attendeeRepository->shouldReceive('findWhere')->andReturn(new Collection);

        $this->completeOrderHandler->handle($orderShortId, $orderData);
    }

    private function createMockCompleteOrderDTO(): CompleteOrderDTO
    {
        $orderDTO = new CompleteOrderOrderDTO(
            first_name: 'John',
            last_name: 'Doe',
            email: 'john@example.com',
            questions: null,
        );

        $attendeeDTO = new CompleteOrderProductDataDTO(
            product_price_id: 1,
            first_name: 'John',
            last_name: 'Doe',
            email: 'john@example.com'
        );

        return new CompleteOrderDTO(
            order: $orderDTO,
            products: new Collection([$attendeeDTO]), event_id: 1
        );
    }

    private function createMockOrder(OrderStatus $status = OrderStatus::RESERVED): OrderDomainObject|MockInterface
    {
        return (new OrderDomainObject)
            ->setEmail(null)
            ->setSessionId('test-session-id')
            ->setReservedUntil(Carbon::now()->addHour()->toDateTimeString())
            ->setStatus($status->name)
            ->setId(1)
            ->setEventId(1)
            ->setLocale('en')
            ->setTotalGross(10)
            ->setOrderItems(new Collection([
                $this->createMockOrderItem(),
            ]));
    }

    private function createMockOrderItem(): OrderItemDomainObject|MockInterface
    {
        return (new OrderItemDomainObject)
            ->setId(1)
            ->setProductId(1)
            ->setQuantity(1)
            ->setPrice(10)
            ->setTotalGross(10)
            ->setProductPriceId(1);
    }

    private function createMockProductPrice(): ProductPriceDomainObject|MockInterface
    {
        $productPrice = Mockery::mock(ProductPriceDomainObject::class);
        $productPrice->shouldReceive('getId')->andReturn(1);
        $productPrice->shouldReceive('getProductId')->andReturn(1);

        return $productPrice;
    }

    private function createMockAttendee(): AttendeeDomainObject|MockInterface
    {
        $attendee = Mockery::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getId')->andReturn(1);
        $attendee->shouldReceive('getProductId')->andReturn(1);

        return $attendee;
    }

    private function createMockEventSetting(): EventSettingDomainObject
    {
        return (new EventSettingDomainObject)
            ->setId(1)
            ->setEventId(1);
    }
}
