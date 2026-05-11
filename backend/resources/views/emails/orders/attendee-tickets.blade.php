@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \HiEvents\DomainObjects\EventSettingDomainObject $eventSettings */ @endphp
@php /** @var \HiEvents\DomainObjects\OrganizerDomainObject $organizer */ @endphp
@php /** @var \HiEvents\DomainObjects\OrderDomainObject $order */ @endphp
@php /** @var array<int, array{attendee: \HiEvents\DomainObjects\AttendeeDomainObject, ticketUrl: string}> $ticketEntries */ @endphp
@php /** @see \HiEvents\Mail\Attendee\AttendeeTicketsMail */ @endphp

<x-mail::message>
# {{ __('Your tickets for :event', ['event' => $event->getTitle()]) }} 🎉

@if($order->isOrderAwaitingOfflinePayment())
<div style="border-radius: 4px; background-color: #f8d7da; color: #842029; margin-bottom: 1.5rem; padding: 1rem;">
<p>
{{ __('ℹ️ This order is pending payment. Tickets have been issued but will not be valid until payment is received.') }}
</p>
</div>
@endif

{{ __('You have :count tickets in this order. Each ticket has its own unique link below — forward an individual link to each guest.', ['count' => count($ticketEntries)]) }}

@foreach($ticketEntries as $entry)
@php
    $attendee = $entry['attendee'];
    $ticketUrl = $entry['ticketUrl'];
    $attendeeName = trim(($attendee->getFirstName() ?? '') . ' ' . ($attendee->getLastName() ?? ''));
    $displayName = $attendeeName !== '' ? $attendeeName : __('Ticket :number', ['number' => $loop->iteration]);
    $forwardSubject = rawurlencode(__('Your ticket for :event', ['event' => $event->getTitle()]));
    $forwardBody = rawurlencode(__("Hi,\n\nHere's your ticket for :event:\n\n:url\n\nClick the link to view your ticket and enter your details before the event.", [
        'event' => $event->getTitle(),
        'url' => $ticketUrl,
    ]));
@endphp
---

**{{ $displayName }}** &middot; {{ $loop->iteration }} / {{ count($ticketEntries) }}

<x-mail::button :url="$ticketUrl">
{{ __('View ticket') }}
</x-mail::button>

[{{ __('Forward this ticket to the attendee') }}](mailto:?subject={{ $forwardSubject }}&body={{ $forwardBody }})

{{ __('Or copy this link:') }}
`{{ $ticketUrl }}`

@endforeach

---

{{ __('If you have any questions, please reply to this email or contact the event organizer at') }} <a href="mailto:{{$eventSettings->getSupportEmail()}}">{{$eventSettings->getSupportEmail()}}</a>.

{{ __('Best regards,') }}<br>
{{ $organizer->getName() ?: config('app.name') }}

</x-mail::message>
