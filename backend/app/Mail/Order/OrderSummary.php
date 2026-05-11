<?php

namespace HiEvents\Mail\Order;

use Barryvdh\DomPDF\Facade\Pdf;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\Email\DTO\RenderedEmailTemplateDTO;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * @uses /backend/resources/views/emails/orders/summary.blade.php
 */
class OrderSummary extends BaseMail
{
    private readonly ?RenderedEmailTemplateDTO $renderedTemplate;

    public function __construct(
        private readonly OrderDomainObject        $order,
        private readonly EventDomainObject        $event,
        private readonly OrganizerDomainObject    $organizer,
        private readonly EventSettingDomainObject $eventSettings,
        private readonly ?InvoiceDomainObject     $invoice,
        ?RenderedEmailTemplateDTO                 $renderedTemplate = null,
    )
    {
        $this->renderedTemplate = $renderedTemplate;

        parent::__construct();
    }

    public function envelope(): Envelope
    {
        $subject = $this->renderedTemplate?->subject ?? __('Your Order is Confirmed!') . '  🎉';

        return new Envelope(
            replyTo: $this->eventSettings->getSupportEmail(),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        if ($this->renderedTemplate) {
            return new Content(
                markdown: 'emails.custom-template',
                with: [
                    'renderedBody' => $this->renderedTemplate->body,
                    'renderedCta' => $this->renderedTemplate->cta,
                    'eventSettings' => $this->eventSettings,
                ]
            );
        }

        // Fallback to original template
        return new Content(
            markdown: 'emails.orders.summary',
            with: [
                'eventSettings' => $this->eventSettings,
                'event' => $this->event,
                'order' => $this->order,
                'organizer' => $this->organizer,
                'orderUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::ORDER_SUMMARY),
                    $this->event->getId(),
                    $this->order->getShortId(),
                ),
                'buyerTickets' => $this->buildBuyerTicketEntries(),
            ]
        );
    }

    /**
     * Per-ticket entries to embed in the buyer's order-summary email: every attendee
     * whose email matches the buyer's. Each entry includes the per-attendee ticket URL
     * so the buyer can forward individual tickets to guests.
     *
     * @return array<int, array{name: string, ticketUrl: string}>
     */
    private function buildBuyerTicketEntries(): array
    {
        $attendees = $this->order->getAttendees();
        if ($attendees === null) {
            return [];
        }

        $buyerEmail = strtolower(trim((string)$this->order->getEmail()));

        $entries = [];
        foreach ($attendees as $attendee) {
            $attendeeEmail = strtolower(trim((string)$attendee->getEmail()));
            if ($attendeeEmail !== $buyerEmail) {
                continue;
            }

            $name = trim(($attendee->getFirstName() ?? '') . ' ' . ($attendee->getLastName() ?? ''));

            $entries[] = [
                'name' => $name,
                'ticketUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::ATTENDEE_TICKET),
                    $this->event->getId(),
                    $attendee->getShortId(),
                ),
            ];
        }

        return $entries;
    }

    public function attachments(): array
    {
        if ($this->invoice === null) {
            return [];
        }

        $invoice = Pdf::loadView('invoice', [
            'order' => $this->order,
            'event' => $this->event,
            'organizer' => $this->organizer,
            'eventSettings' => $this->eventSettings,
            'invoice' => $this->invoice,
        ]);

        return [
            Attachment::fromData(
                static fn() => $invoice->output(),
                'invoice.pdf',
            )->withMime('application/pdf'),
        ];
    }
}
