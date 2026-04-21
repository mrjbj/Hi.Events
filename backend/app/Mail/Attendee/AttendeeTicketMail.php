<?php

namespace HiEvents\Mail\Attendee;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\StringHelper;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\Contact\ContactLinkBuilder;
use HiEvents\Services\Domain\Email\DTO\RenderedEmailTemplateDTO;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;

/**
 * @uses /backend/resources/views/emails/orders/attendee-ticket.blade.php
 */
class AttendeeTicketMail extends BaseMail
{
    private readonly ?RenderedEmailTemplateDTO $renderedTemplate;

    public function __construct(
        private readonly OrderDomainObject        $order,
        private readonly AttendeeDomainObject     $attendee,
        private readonly EventDomainObject        $event,
        private readonly EventSettingDomainObject $eventSettings,
        private readonly OrganizerDomainObject    $organizer,
        ?RenderedEmailTemplateDTO                 $renderedTemplate = null,
    )
    {
        parent::__construct();
        $this->renderedTemplate = $renderedTemplate;
    }

    public function envelope(): Envelope
    {
        $subject = $this->renderedTemplate?->subject ?? __('🎟️ Your Ticket for :event', [
            'event' => Str::limit($this->event->getTitle(), 50)
        ]);

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

        // If no template is provided, use the default blade template
        return new Content(
            markdown: 'emails.orders.attendee-ticket',
            with: [
                'event' => $this->event,
                'attendee' => $this->attendee,
                'eventSettings' => $this->eventSettings,
                'organizer' => $this->organizer,
                'order' => $this->order,
                'ticketUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::ATTENDEE_TICKET),
                    $this->event->getId(),
                    $this->attendee->getShortId(),
                ),
                'profileUrl' => $this->buildProfileUrl(),
            ]
        );
    }

    /**
     * Builds a signed ?c=<token> URL to /contacts/me for the attendee.
     * Returns null (not the bare URL) when the attendee's email isn't a
     * known contact — we don't want to show "Update your profile" to
     * someone who doesn't have a profile yet.
     */
    private function buildProfileUrl(): ?string
    {
        $linkBuilder = app(ContactLinkBuilder::class);
        $baseUrl = Url::getFrontEndUrlFromConfig(Url::CONTACT_PROFILE);
        $signed = $linkBuilder->forRecipient(
            email: $this->attendee->getEmail(),
            accountId: $this->event->getAccountId(),
            url: $baseUrl,
        );
        return $signed === $baseUrl ? null : $signed;
    }

    public function attachments(): array
    {
        $startDateTime = Carbon::parse($this->event->getStartDate(), $this->event->getTimezone());
        $endDateTime = $this->event->getEndDate() ? Carbon::parse($this->event->getEndDate(), $this->event->getTimezone()) : null;

        $event = Event::create()
            ->name($this->event->getTitle())
            ->uniqueIdentifier('event-' . $this->attendee->getId())
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
            Attachment::fromData(static fn() => $calendar, 'event.ics')
                ->withMime('text/calendar')
        ];
    }
}
