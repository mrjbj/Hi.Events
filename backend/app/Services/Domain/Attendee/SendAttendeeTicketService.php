<?php

namespace HiEvents\Services\Domain\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Services\Domain\Email\MailBuilderService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Collection;

class SendAttendeeTicketService
{
    public function __construct(
        private readonly Mailer             $mailer,
        private readonly MailBuilderService $mailBuilderService,
    )
    {
    }

    public function send(
        OrderDomainObject        $order,
        AttendeeDomainObject     $attendee,
        EventDomainObject        $event,
        EventSettingDomainObject $eventSettings,
        OrganizerDomainObject    $organizer,
    ): void
    {
        $mail = $this->mailBuilderService->buildAttendeeTicketMail(
            $attendee,
            $order,
            $event,
            $eventSettings,
            $organizer
        );

        $this->mailer
            ->to($attendee->getEmail())
            ->locale($attendee->getLocale())
            ->send($mail);
    }

    /**
     * Sends one combined email containing all tickets to a single recipient. Used
     * when several attendees share the same email — typical of bundle/sponsor-table
     * purchases where the buyer holds tickets for guests who'll provide details later.
     *
     * @param Collection<int, AttendeeDomainObject> $attendees Must all share the same recipient email.
     */
    public function sendCombined(
        OrderDomainObject        $order,
        Collection               $attendees,
        EventDomainObject        $event,
        EventSettingDomainObject $eventSettings,
        OrganizerDomainObject    $organizer,
    ): void
    {
        if ($attendees->isEmpty()) {
            return;
        }

        $recipient = $attendees->first()->getEmail();
        $locale = $attendees->first()->getLocale();

        $mail = $this->mailBuilderService->buildAttendeeTicketsMail(
            $attendees,
            $order,
            $event,
            $eventSettings,
            $organizer,
        );

        $this->mailer
            ->to($recipient)
            ->locale($locale)
            ->send($mail);
    }
}
