<?php

namespace Tests\Unit\Services\Domain\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\TransactionalEmailType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Mail\Organizer\OrderSummaryForOrganizer;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Domain\Email\MailBuilderService;
use HiEvents\Services\Domain\Email\TransactionalEmailTrackingService;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SendOrderDetailsServiceTest extends TestCase
{
    private SendOrderDetailsService $service;
    private MockInterface|EventRepositoryInterface $eventRepository;
    private MockInterface|OrderRepositoryInterface $orderRepository;
    private MockInterface|Mailer $mailer;
    private MockInterface|SendAttendeeTicketService $sendAttendeeTicketService;
    private MockInterface|MailBuilderService $mailBuilderService;
    private MockInterface|TransactionalEmailTrackingService $trackingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->mailer = Mockery::mock(Mailer::class);
        $this->sendAttendeeTicketService = Mockery::mock(SendAttendeeTicketService::class);
        $this->mailBuilderService = Mockery::mock(MailBuilderService::class);
        $this->trackingService = Mockery::mock(TransactionalEmailTrackingService::class);

        $this->service = new SendOrderDetailsService(
            $this->eventRepository,
            $this->orderRepository,
            $this->mailer,
            $this->sendAttendeeTicketService,
            $this->mailBuilderService,
            $this->trackingService,
        );
    }

    public function testOrganizerNotificationUsesOrganizerOrderSummaryEmailType(): void
    {
        $orderId = 42;
        $eventId = 7;
        $accountId = 3;
        $organizerEmail = 'organizer@example.com';

        [$orderStub] = $this->arrangeHappyPath(
            orderId: $orderId,
            eventId: $eventId,
            accountId: $accountId,
            organizerEmail: $organizerEmail,
            notifyOrganizer: true,
        );

        $allCallArgs = [];
        $this->trackingService->shouldReceive('recordAndSend')
            ->twice()
            ->withArgs(function (...$args) use (&$allCallArgs) {
                $allCallArgs[] = $args;
                return true;
            })
            ->andReturnNull();

        $this->service->sendOrderSummaryAndTicketEmails($orderStub);

        $organizerCall = null;
        foreach ($allCallArgs as $args) {
            foreach ($args as $arg) {
                if ($arg instanceof TransactionalEmailType && $arg === TransactionalEmailType::ORGANIZER_ORDER_SUMMARY) {
                    $organizerCall = $args;
                    break 2;
                }
            }
        }

        $this->assertNotNull($organizerCall, 'Expected recordAndSend to be called with ORGANIZER_ORDER_SUMMARY.');

        $hasOrganizerMail = false;
        $hasOrganizerRecipient = false;
        foreach ($organizerCall as $arg) {
            if ($arg === $organizerEmail) {
                $hasOrganizerRecipient = true;
            }
            if ($arg instanceof OrderSummaryForOrganizer) {
                $hasOrganizerMail = true;
            }
        }

        $this->assertTrue($hasOrganizerRecipient, 'Organizer call should use the organizer email as recipient.');
        $this->assertTrue($hasOrganizerMail, 'Organizer call should pass OrderSummaryForOrganizer mailable.');
    }

    public function testOrganizerNotificationSkippedWhenNotifyDisabled(): void
    {
        $orderId = 42;
        $eventId = 7;
        $accountId = 3;

        [$orderStub] = $this->arrangeHappyPath(
            orderId: $orderId,
            eventId: $eventId,
            accountId: $accountId,
            organizerEmail: 'organizer@example.com',
            notifyOrganizer: false,
        );

        $seenTypes = [];
        $this->trackingService->shouldReceive('recordAndSend')
            ->withArgs(function (...$args) use (&$seenTypes) {
                foreach ($args as $arg) {
                    if ($arg instanceof TransactionalEmailType) {
                        $seenTypes[] = $arg;
                    }
                }
                return true;
            })
            ->andReturnNull();

        $this->service->sendOrderSummaryAndTicketEmails($orderStub);

        $this->assertNotContains(
            TransactionalEmailType::ORGANIZER_ORDER_SUMMARY,
            $seenTypes,
            'Organizer notification must not be dispatched when notifyOrganizerOfNewOrders is false.'
        );
    }

    public function testNoAttendeeEmailsSentWhenAllAttendeesShareBuyerEmail(): void
    {
        $buyerEmail = 'buyer@example.com';
        $order = $this->makeOrder($buyerEmail, [
            ['email' => $buyerEmail],
            ['email' => $buyerEmail],
            ['email' => $buyerEmail],
        ]);
        $event = $this->makeEvent();

        $this->wireGroupingTest($order, $event);

        $this->sendAttendeeTicketService->shouldNotReceive('send');
        $this->sendAttendeeTicketService->shouldNotReceive('sendCombined');

        $this->service->sendOrderSummaryAndTicketEmails($order);

        $this->assertTrue(true);
    }

    public function testOnePerRecipientWhenAttendeesHaveUniqueEmails(): void
    {
        $buyerEmail = 'buyer@example.com';
        $order = $this->makeOrder($buyerEmail, [
            ['email' => 'alice@example.com'],
            ['email' => 'bob@example.com'],
            ['email' => 'cara@example.com'],
        ]);
        $event = $this->makeEvent();

        $this->wireGroupingTest($order, $event);

        $this->sendAttendeeTicketService
            ->shouldReceive('send')
            ->times(3);

        $this->sendAttendeeTicketService->shouldNotReceive('sendCombined');

        $this->service->sendOrderSummaryAndTicketEmails($order);

        $this->assertTrue(true);
    }

    public function testGroupedRecipientUsesSendCombined(): void
    {
        $buyerEmail = 'buyer@example.com';
        $order = $this->makeOrder($buyerEmail, [
            ['email' => 'group@example.com'],
            ['email' => 'group@example.com'],
            ['email' => 'solo@example.com'],
        ]);
        $event = $this->makeEvent();

        $this->wireGroupingTest($order, $event);

        $this->sendAttendeeTicketService
            ->shouldReceive('sendCombined')
            ->once()
            ->withArgs(function (...$args) {
                $attendees = $args[1] ?? null;
                if (!$attendees instanceof Collection) {
                    return false;
                }
                return $attendees->count() === 2
                    && strtolower($attendees->first()->getEmail()) === 'group@example.com';
            });

        $this->sendAttendeeTicketService
            ->shouldReceive('send')
            ->once()
            ->withArgs(function (...$args) {
                $attendee = $args[1] ?? null;
                return $attendee instanceof AttendeeDomainObject
                    && strtolower($attendee->getEmail()) === 'solo@example.com';
            });

        $this->service->sendOrderSummaryAndTicketEmails($order);

        $this->assertTrue(true);
    }

    public function testBuyerEmailGroupIsSkippedEvenWhenOtherRecipientsExist(): void
    {
        $buyerEmail = 'buyer@example.com';
        $order = $this->makeOrder($buyerEmail, [
            ['email' => $buyerEmail],
            ['email' => $buyerEmail],
            ['email' => $buyerEmail],
            ['email' => 'other@example.com'],
            ['email' => 'other@example.com'],
        ]);
        $event = $this->makeEvent();

        $this->wireGroupingTest($order, $event);

        $this->sendAttendeeTicketService
            ->shouldReceive('sendCombined')
            ->once()
            ->withArgs(function (...$args) {
                $attendees = $args[1] ?? null;
                if (!$attendees instanceof Collection) {
                    return false;
                }
                return $attendees->count() === 2
                    && strtolower($attendees->first()->getEmail()) === 'other@example.com';
            });

        $this->sendAttendeeTicketService->shouldNotReceive('send');

        $this->service->sendOrderSummaryAndTicketEmails($order);

        $this->assertTrue(true);
    }

    /**
     * @return array{0: OrderDomainObject} Returns the hydrated order mock.
     */
    private function arrangeHappyPath(
        int $orderId,
        int $eventId,
        int $accountId,
        string $organizerEmail,
        bool $notifyOrganizer,
    ): array {
        $organizer = Mockery::mock(OrganizerDomainObject::class);
        $organizer->shouldReceive('getEmail')->andReturn($organizerEmail);

        $eventSettings = Mockery::mock(EventSettingDomainObject::class);
        $eventSettings->shouldReceive('getNotifyOrganizerOfNewOrders')->andReturn($notifyOrganizer);

        $event = Mockery::mock(EventDomainObject::class);
        $event->shouldReceive('getId')->andReturn($eventId);
        $event->shouldReceive('getAccountId')->andReturn($accountId);
        $event->shouldReceive('getOrganizer')->andReturn($organizer);
        $event->shouldReceive('getEventSettings')->andReturn($eventSettings);
        $event->shouldReceive('getTitle')->andReturn('Test Event');
        $event->shouldReceive('getCurrency')->andReturn('USD');

        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn($orderId);
        $order->shouldReceive('getEventId')->andReturn($eventId);
        $order->shouldReceive('isOrderCompleted')->andReturn(true);
        $order->shouldReceive('isOrderAwaitingOfflinePayment')->andReturn(false);
        $order->shouldReceive('isOrderFailed')->andReturn(false);
        $order->shouldReceive('getIsManuallyCreated')->andReturn(false);
        $order->shouldReceive('getAttendees')->andReturn(collect());
        $order->shouldReceive('getLatestInvoice')->andReturn(null);
        $order->shouldReceive('getEmail')->andReturn('buyer@example.com');
        $order->shouldReceive('getLocale')->andReturn('en');
        $order->shouldReceive('getTotalGross')->andReturn(0);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findById')->with($orderId)->andReturn($order);

        $this->eventRepository->shouldReceive('loadRelation')
            ->with(Mockery::type(Relationship::class))
            ->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->with($eventId)->andReturn($event);

        $customerMail = Mockery::mock(OrderSummary::class);
        $customerMail->shouldReceive('envelope')->andReturn(new Envelope(subject: 'Your Order'));
        $this->mailBuilderService->shouldReceive('buildOrderSummaryMail')->andReturn($customerMail);

        return [$order];
    }

    /**
     * Common wiring for the group-by-email tests: hydrates repositories, builds the
     * order summary mail, and accepts (without asserting) any recordAndSend calls
     * the customer-summary path triggers via TransactionalEmailTrackingService.
     */
    private function wireGroupingTest(OrderDomainObject $order, EventDomainObject $event): void
    {
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findById')->andReturn($order);

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->andReturn($event);

        $summary = Mockery::mock(OrderSummary::class);
        $summary->shouldReceive('envelope')->andReturn(new Envelope(subject: 'Your Order'));
        $this->mailBuilderService
            ->shouldReceive('buildOrderSummaryMail')
            ->once()
            ->andReturn($summary);

        $this->trackingService
            ->shouldReceive('recordAndSend')
            ->andReturnNull();
    }

    private function makeOrder(string $buyerEmail, array $attendeeSpecs): OrderDomainObject
    {
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(1);
        $order->shouldReceive('getEventId')->andReturn(10);
        $order->shouldReceive('getEmail')->andReturn($buyerEmail);
        $order->shouldReceive('getLocale')->andReturn('en');
        $order->shouldReceive('isOrderCompleted')->andReturn(true);
        $order->shouldReceive('isOrderAwaitingOfflinePayment')->andReturn(false);
        $order->shouldReceive('isOrderFailed')->andReturn(false);
        $order->shouldReceive('getIsManuallyCreated')->andReturn(true);
        $order->shouldReceive('getLatestInvoice')->andReturn(null);
        $order->shouldReceive('getTotalGross')->andReturn(0);

        $attendees = new Collection(array_map(function ($spec, $i) {
            $a = Mockery::mock(AttendeeDomainObject::class);
            $a->shouldReceive('getEmail')->andReturn($spec['email']);
            $a->shouldReceive('getLocale')->andReturn('en');
            $a->shouldReceive('getId')->andReturn($i + 1);
            return $a;
        }, $attendeeSpecs, array_keys($attendeeSpecs)));

        $order->shouldReceive('getAttendees')->andReturn($attendees);

        return $order;
    }

    private function makeEvent(): EventDomainObject
    {
        $event = Mockery::mock(EventDomainObject::class);
        $event->shouldReceive('getId')->andReturn(10);
        $event->shouldReceive('getAccountId')->andReturn(1);
        $event->shouldReceive('getTitle')->andReturn('Test Event');

        $organizer = Mockery::mock(OrganizerDomainObject::class);
        $organizer->shouldReceive('getId')->andReturn(1);
        $organizer->shouldReceive('getEmail')->andReturn('organizer@example.com');
        $organizer->shouldReceive('getName')->andReturn('Organizer');

        $settings = Mockery::mock(EventSettingDomainObject::class);
        $settings->shouldReceive('getNotifyOrganizerOfNewOrders')->andReturn(false);

        $event->shouldReceive('getOrganizer')->andReturn($organizer);
        $event->shouldReceive('getEventSettings')->andReturn($settings);

        return $event;
    }
}
