<?php

namespace Tests\Unit\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\GetOrderPublicDTO;
use HiEvents\Services\Application\Handlers\Order\GetOrderPublicHandler;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use HiEvents\Services\Domain\Contact\DTO\ContactTokenPayload;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class GetOrderPublicHandlerTest extends TestCase
{
    private OrderRepositoryInterface $orderRepository;
    private CheckoutSessionManagementService $sessionService;
    private ContactSignedTokenService $tokenService;
    private GetOrderPublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderRepository = m::mock(OrderRepositoryInterface::class);
        $this->sessionService = m::mock(CheckoutSessionManagementService::class);
        $this->tokenService = new ContactSignedTokenService();
        $this->handler = new GetOrderPublicHandler(
            $this->orderRepository,
            $this->sessionService,
            $this->tokenService,
        );
    }

    public function testAttachesOneTokenPerUniqueContactIdAcrossAttendees(): void
    {
        $order = $this->buildOrder(accountId: 7, attendeeContactIds: [101, 102, 101, null]);
        $this->primeRepositoryToReturn($order);

        $result = $this->handler->handle(new GetOrderPublicDTO(
            eventId: 1, orderShortId: 'SHORT', includeEventInResponse: false,
        ));

        $tokens = $result->getAttendeeContactTokens();
        $this->assertCount(2, $tokens, 'should dedupe contact 101 and skip null');
        $contactIds = array_column($tokens, 'contact_id');
        sort($contactIds);
        $this->assertSame([101, 102], $contactIds);
    }

    public function testMintedTokensVerifyAgainstOriginalContactAndAccount(): void
    {
        $order = $this->buildOrder(accountId: 42, attendeeContactIds: [555]);
        $this->primeRepositoryToReturn($order);

        $result = $this->handler->handle(new GetOrderPublicDTO(
            eventId: 1, orderShortId: 'SHORT', includeEventInResponse: false,
        ));

        $tokens = $result->getAttendeeContactTokens();
        $this->assertCount(1, $tokens);

        $payload = $this->tokenService->verify($tokens[0]['token']);
        $this->assertInstanceOf(ContactTokenPayload::class, $payload);
        $this->assertSame(555, $payload->contactId);
        $this->assertSame(42, $payload->accountId);
    }

    public function testReturnsEmptyTokensArrayWhenNoAttendeesHaveContacts(): void
    {
        $order = $this->buildOrder(accountId: 7, attendeeContactIds: [null, null]);
        $this->primeRepositoryToReturn($order);

        $result = $this->handler->handle(new GetOrderPublicDTO(
            eventId: 1, orderShortId: 'SHORT', includeEventInResponse: false,
        ));

        $this->assertSame([], $result->getAttendeeContactTokens());
    }

    public function testReturnsEmptyTokensArrayWhenOrderHasNoAttendeesLoaded(): void
    {
        $order = new OrderDomainObject();
        $order->setEventId(1);
        $order->setEvent($this->buildEvent(7));
        $order->setStatus(OrderStatus::COMPLETED->name);
        $order->setAttendees(null);
        $this->primeRepositoryToReturn($order);

        $result = $this->handler->handle(new GetOrderPublicDTO(
            eventId: 1, orderShortId: 'SHORT', includeEventInResponse: false,
        ));

        $this->assertSame([], $result->getAttendeeContactTokens());
    }

    private function buildOrder(int $accountId, array $attendeeContactIds): OrderDomainObject
    {
        $attendees = new Collection();
        foreach ($attendeeContactIds as $idx => $contactId) {
            $a = new AttendeeDomainObject();
            $a->setId($idx + 1);
            $a->setContactId($contactId);
            $attendees->push($a);
        }
        $order = new OrderDomainObject();
        $order->setEventId(1);
        $order->setEvent($this->buildEvent($accountId));
        $order->setStatus(OrderStatus::COMPLETED->name);
        $order->setAttendees($attendees);
        return $order;
    }

    private function buildEvent(int $accountId): EventDomainObject
    {
        $event = new EventDomainObject();
        $event->setAccountId($accountId);
        return $event;
    }

    private function primeRepositoryToReturn(OrderDomainObject $order): void
    {
        $this->orderRepository
            ->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository
            ->shouldReceive('findByShortId')->andReturn($order);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
