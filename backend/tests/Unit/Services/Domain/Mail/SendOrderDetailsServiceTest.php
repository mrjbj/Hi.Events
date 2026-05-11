<?php

namespace Tests\Unit\Services\Domain\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Domain\Email\MailBuilderService;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class SendOrderDetailsServiceTest extends TestCase
{
    private OrderRepositoryInterface $orderRepository;
    private EventRepositoryInterface $eventRepository;
    private Mailer $mailer;
    private SendAttendeeTicketService $sendAttendeeTicketService;
    private MailBuilderService $mailBuilderService;
    private SendOrderDetailsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = m::mock(OrderRepositoryInterface::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->mailer = m::mock(Mailer::class);
        $this->sendAttendeeTicketService = m::mock(SendAttendeeTicketService::class);
        $this->mailBuilderService = m::mock(MailBuilderService::class);

        $this->service = new SendOrderDetailsService(
            $this->eventRepository,
            $this->orderRepository,
            $this->mailer,
            $this->sendAttendeeTicketService,
            $this->mailBuilderService,
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

        $this->wireRepositoriesAndOrderSummary($order, $event);

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

        $this->wireRepositoriesAndOrderSummary($order, $event);

        $this->sendAttendeeTicketService
            ->shouldReceive('send')
            ->times(3)
            ->withArgs(function ($args) {
                return is_array($args)
                    ? true
                    : true;
            });

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

        $this->wireRepositoriesAndOrderSummary($order, $event);

        $this->sendAttendeeTicketService
            ->shouldReceive('sendCombined')
            ->once()
            ->withArgs(function (...$args) {
                $kwargs = $this->extractKwargs($args);
                /** @var Collection $attendees */
                $attendees = $kwargs['attendees'];
                return $attendees->count() === 2
                    && strtolower($attendees->first()->getEmail()) === 'group@example.com';
            });

        $this->sendAttendeeTicketService
            ->shouldReceive('send')
            ->once()
            ->withArgs(function (...$args) {
                $kwargs = $this->extractKwargs($args);
                return strtolower($kwargs['attendee']->getEmail()) === 'solo@example.com';
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

        $this->wireRepositoriesAndOrderSummary($order, $event);

        $this->sendAttendeeTicketService
            ->shouldReceive('sendCombined')
            ->once()
            ->withArgs(function (...$args) {
                $kwargs = $this->extractKwargs($args);
                /** @var Collection $attendees */
                $attendees = $kwargs['attendees'];
                return $attendees->count() === 2
                    && strtolower($attendees->first()->getEmail()) === 'other@example.com';
            });

        $this->sendAttendeeTicketService->shouldNotReceive('send');

        $this->service->sendOrderSummaryAndTicketEmails($order);

        $this->assertTrue(true);
    }

    private function wireRepositoriesAndOrderSummary(OrderDomainObject $order, EventDomainObject $event): void
    {
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findById')->andReturn($order);

        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->andReturn($event);

        // Order summary path always runs — return a mock summary mail and expect a single send.
        $summary = m::mock(OrderSummary::class);
        $this->mailBuilderService
            ->shouldReceive('buildOrderSummaryMail')
            ->once()
            ->andReturn($summary);

        $pending = m::mock(PendingMail::class);
        $pending->shouldReceive('locale')->andReturnSelf();
        $pending->shouldReceive('send')->andReturnNull();

        $this->mailer
            ->shouldReceive('to')
            ->andReturn($pending);
    }

    private function makeOrder(string $buyerEmail, array $attendeeSpecs): OrderDomainObject
    {
        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(1);
        $order->shouldReceive('getEventId')->andReturn(10);
        $order->shouldReceive('getEmail')->andReturn($buyerEmail);
        $order->shouldReceive('getLocale')->andReturn('en');
        $order->shouldReceive('isOrderCompleted')->andReturn(true);
        $order->shouldReceive('isOrderAwaitingOfflinePayment')->andReturn(false);
        $order->shouldReceive('isOrderFailed')->andReturn(false);
        $order->shouldReceive('getIsManuallyCreated')->andReturn(true); // skip organizer notify path
        $order->shouldReceive('getLatestInvoice')->andReturn(null);

        $attendees = new Collection(array_map(function ($spec, $i) {
            $a = m::mock(AttendeeDomainObject::class);
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
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getId')->andReturn(10);
        $event->shouldReceive('getAccountId')->andReturn(1);

        $organizer = m::mock(OrganizerDomainObject::class);
        $organizer->shouldReceive('getId')->andReturn(1);
        $organizer->shouldReceive('getEmail')->andReturn('organizer@example.com');
        $organizer->shouldReceive('getName')->andReturn('Organizer');

        $settings = m::mock(EventSettingDomainObject::class);
        $settings->shouldReceive('getNotifyOrganizerOfNewOrders')->andReturn(false);

        $event->shouldReceive('getOrganizer')->andReturn($organizer);
        $event->shouldReceive('getEventSettings')->andReturn($settings);

        return $event;
    }

    private function extractKwargs(array $args): array
    {
        // Mockery passes positional args; the service uses named args. PHP collapses them
        // into positional order matching the called method signature.
        return [
            'order' => $args[0] ?? null,
            'attendees' => $args[1] ?? null,
            'attendee' => $args[1] ?? null, // for send(), arg 1 is attendee; for sendCombined() it's attendees
            'event' => $args[2] ?? null,
        ];
    }
}
