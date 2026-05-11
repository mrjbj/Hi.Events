<?php

namespace HiEvents\Services\Domain\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Mail\Order\OrderFailed;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Mail\Organizer\OrderSummaryForOrganizer;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Attendee\SendAttendeeTicketService;
use HiEvents\Services\Domain\Email\MailBuilderService;
use Illuminate\Mail\Mailer;

class SendOrderDetailsService
{
    public function __construct(
        private readonly EventRepositoryInterface  $eventRepository,
        private readonly OrderRepositoryInterface  $orderRepository,
        private readonly Mailer                    $mailer,
        private readonly SendAttendeeTicketService $sendAttendeeTicketService,
        private readonly MailBuilderService        $mailBuilderService,
    )
    {
    }

    public function sendOrderSummaryAndTicketEmails(OrderDomainObject $order): void
    {
        $order = $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->loadRelation(AttendeeDomainObject::class)
            ->loadRelation(InvoiceDomainObject::class)
            ->findById($order->getId());

        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->findById($order->getEventId());

        if ($order->isOrderCompleted() || $order->isOrderAwaitingOfflinePayment()) {
            $this->sendOrderSummaryEmails($order, $event);
            $this->sendAttendeeTicketEmails($order, $event);
        }

        if ($order->isOrderFailed()) {
            $this->mailer
                ->to($order->getEmail())
                ->locale($order->getLocale())
                ->send(new OrderFailed(
                    order: $order,
                    event: $event,
                    organizer: $event->getOrganizer(),
                    eventSettings: $event->getEventSettings(),
                ));
        }
    }

    public function sendCustomerOrderSummary(
        OrderDomainObject        $order,
        EventDomainObject        $event,
        OrganizerDomainObject    $organizer,
        EventSettingDomainObject $eventSettings,
        ?InvoiceDomainObject     $invoice = null
    ): void
    {
        $mail = $this->mailBuilderService->buildOrderSummaryMail(
            $order,
            $event,
            $eventSettings,
            $organizer,
            $invoice
        );

        $this->mailer
            ->to($order->getEmail())
            ->locale($order->getLocale())
            ->send($mail);
    }

    /**
     * Groups attendees by recipient email so each unique recipient gets exactly
     * one email containing all of their tickets:
     *  - Buyer's email (matches $order->getEmail()): skipped here — the buyer
     *    already receives the OrderSummary email, which surfaces their tickets.
     *  - Other recipients: one email per address. Single-attendee groups use the
     *    existing AttendeeTicketMail; multi-attendee groups use AttendeeTicketsMail.
     *
     * Previously this method deduped by email but only sent the first attendee's
     * ticket — meaning tickets 2..N went missing whenever attendees shared an email.
     */
    private function sendAttendeeTicketEmails(OrderDomainObject $order, EventDomainObject $event): void
    {
        $buyerEmail = strtolower(trim((string)$order->getEmail()));

        $groups = collect($order->getAttendees() ?? [])
            ->groupBy(fn ($attendee) => strtolower(trim((string)$attendee->getEmail())));

        foreach ($groups as $email => $groupAttendees) {
            if ($email === '' || $email === $buyerEmail) {
                continue;
            }

            if ($groupAttendees->count() === 1) {
                $this->sendAttendeeTicketService->send(
                    order: $order,
                    attendee: $groupAttendees->first(),
                    event: $event,
                    eventSettings: $event->getEventSettings(),
                    organizer: $event->getOrganizer(),
                );
                continue;
            }

            $this->sendAttendeeTicketService->sendCombined(
                order: $order,
                attendees: $groupAttendees->values(),
                event: $event,
                eventSettings: $event->getEventSettings(),
                organizer: $event->getOrganizer(),
            );
        }
    }

    private function sendOrderSummaryEmails(OrderDomainObject $order, EventDomainObject $event): void
    {
        $this->sendCustomerOrderSummary(
            order: $order,
            event: $event,
            organizer: $event->getOrganizer(),
            eventSettings: $event->getEventSettings(),
            invoice: $order->getLatestInvoice(),
        );

        if ($order->getIsManuallyCreated() || !$event->getEventSettings()->getNotifyOrganizerOfNewOrders()) {
            return;
        }

        $this->mailer
            ->to($event->getOrganizer()->getEmail())
            ->send(new OrderSummaryForOrganizer($order, $event));
    }
}
