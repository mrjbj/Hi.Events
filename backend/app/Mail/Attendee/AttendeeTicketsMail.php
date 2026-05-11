<?php

namespace HiEvents\Mail\Attendee;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\StringHelper;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;

/**
 * @uses /backend/resources/views/emails/orders/attendee-tickets.blade.php
 *
 * Sent when several attendees share the same recipient email (e.g. a sponsor table
 * purchased by one person whose guests have not provided their own emails).
 * The single-attendee {@see AttendeeTicketMail} remains in use for 1:1 recipients.
 */
class AttendeeTicketsMail extends BaseMail
{
    /**
     * @param  Collection<int, \HiEvents\DomainObjects\AttendeeDomainObject>  $attendees
     */
    public function __construct(
        private readonly OrderDomainObject $order,
        private readonly Collection $attendees,
        private readonly EventDomainObject $event,
        private readonly EventSettingDomainObject $eventSettings,
        private readonly OrganizerDomainObject $organizer,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        $count = $this->attendees->count();

        $subject = __(':count Tickets for :event', [
            'count' => $count,
            'event' => Str::limit($this->event->getTitle(), 50),
        ]);

        return new Envelope(
            replyTo: $this->eventSettings->getSupportEmail(),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $ticketEntries = $this->attendees->map(function ($attendee) {
            return [
                'attendee' => $attendee,
                'ticketUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::ATTENDEE_TICKET),
                    $this->event->getId(),
                    $attendee->getShortId(),
                ),
            ];
        })->all();

        return new Content(
            markdown: 'emails.orders.attendee-tickets',
            with: [
                'event' => $this->event,
                'eventSettings' => $this->eventSettings,
                'organizer' => $this->organizer,
                'order' => $this->order,
                'ticketEntries' => $ticketEntries,
            ]
        );
    }

    public function attachments(): array
    {
        $startDateTime = Carbon::parse($this->event->getStartDate(), $this->event->getTimezone());
        $endDateTime = $this->event->getEndDate() ? Carbon::parse($this->event->getEndDate(), $this->event->getTimezone()) : null;

        // One calendar entry per event (not per attendee) — multiple identical entries
        // would clutter the recipient's calendar with N copies of the same event.
        $event = Event::create()
            ->name($this->event->getTitle())
            ->uniqueIdentifier('event-'.$this->event->getId().'-order-'.$this->order->getId())
            ->startsAt($startDateTime)
            ->url($this->event->getEventUrl())
            ->organizer($this->organizer->getEmail(), $this->organizer->getName());

        if ($this->event->getDescription()) {
            $event->description(StringHelper::previewFromHtml($this->event->getDescription()));
        }

        if ($this->eventSettings->getLocationDetails()) {
            $event->address($this->eventSettings->getAddressString());
        }

        if ($endDateTime) {
            $event->endsAt($endDateTime);
        }

        $calendar = Calendar::create()
            ->event($event)
            ->get();

        return [
            Attachment::fromData(static fn () => $calendar, 'event.ics')
                ->withMime('text/calendar'),
        ];
    }
}
