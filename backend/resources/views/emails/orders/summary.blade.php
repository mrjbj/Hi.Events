@php use Carbon\Carbon; use HiEvents\Helper\Currency; use HiEvents\Helper\DateHelper; @endphp
@php /** @var \HiEvents\DomainObjects\OrderDomainObject $order */ @endphp
@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \HiEvents\DomainObjects\OrganizerDomainObject $organizer */ @endphp
@php /** @var \HiEvents\DomainObjects\EventSettingDomainObject $eventSettings */ @endphp
@php /** @var string $orderUrl */ @endphp
@php /** @var array<int, array{name: string, ticketUrl: string}> $buyerTickets */ @endphp

@php /** @see \HiEvents\Mail\Order\OrderSummary */ @endphp

<x-mail::message>
# {{ __('Your Order is Confirmed! ') }} 🎉

@if($order->isOrderAwaitingOfflinePayment() === false)

<p>
{{ __('Congratulations! Your order for :eventTitle on :eventDate at :eventTime was successful. Please find your order details below.', ['eventTitle' => $event->getTitle(), 'eventDate' => (new Carbon(DateHelper::convertFromUTC($event->getStartDate(), $event->getTimezone())))->format('F j, Y'), 'eventTime' => (new Carbon(DateHelper::convertFromUTC($event->getStartDate(), $event->getTimezone())))->format('g:i A')]) }}
</p>

@else

<div>
<p>
{{ __('Your order is pending payment. Tickets have been issued but will not be valid until payment is received.') }}
</p>

<div style="border-radius: 4px; background-color: #d7e8f8; color: #204e84; margin-bottom: 1.5rem; padding: 1rem;">
<h2>{{ __('Payment Instructions') }}</h2>
{{ __('Please follow the instructions below to complete your payment.') }}
{!! $eventSettings->getOfflinePaymentInstructions() !!}
</div>
</div>

@endif

<p>

# {{ __('Event Details') }}
**{{ __('Event Name:') }}** {{ $event->getTitle() }}
    <br>
**{{ __('Date & Time:') }}** {{ (new Carbon(DateHelper::convertFromUTC($event->getStartDate(), $event->getTimezone())))->format('F j, Y') }} at {{ (new Carbon(DateHelper::convertFromUTC($event->getStartDate(), $event->getTimezone())))->format('g:i A') }}

</p>

@if($eventSettings->getPostCheckoutMessage() && $order->isOrderCompleted())
<p>

# {{ __('Additional Information') }}

{!! $eventSettings->getPostCheckoutMessage() !!}

</p>
@endif

# {{ __('Order Summary') }}
- **{{ __('Order Number:') }}** {{ $order->getPublicId() }}
- **{{ __('Total Amount:') }}** {{ Currency::format($order->getTotalGross(), $event->getCurrency()) }}

@if(!empty($buyerTickets) && count($buyerTickets) > 1)
{{ __('You have :count tickets in this order. Update each attendee\'s name and registration details before forwarding their individual tickets to them.', ['count' => count($buyerTickets)]) }}

<x-mail::button :url="$orderUrl">
    {{ __('Manage your attendees') }}
</x-mail::button>
@else
<x-mail::button :url="$orderUrl">
    {{ __('View Order Summary & Tickets') }}
</x-mail::button>
@endif

@if(!empty($buyerTickets))
---

# {{ __('Your Tickets') }}

{{ __('Each ticket below has its own unique link. Open a ticket to view the QR code, or forward an individual link to the person attending.') }}

@foreach($buyerTickets as $ticket)
@php
    $displayName = $ticket['name'] !== '' ? $ticket['name'] : __('Ticket :number', ['number' => $loop->iteration]);
    $forwardSubject = rawurlencode(__('Your ticket for :event', ['event' => $event->getTitle()]));
    $forwardBody = rawurlencode(__("Hi,\n\nHere's your ticket for :event:\n\n:url\n\nClick the link to view your ticket and enter your details before the event.", [
        'event' => $event->getTitle(),
        'url' => $ticket['ticketUrl'],
    ]));
@endphp
---

**{{ $displayName }}** &middot; {{ $loop->iteration }} / {{ count($buyerTickets) }}

<x-mail::button :url="$ticket['ticketUrl']">
{{ __('View ticket') }}
</x-mail::button>

[{{ __('Forward this ticket to the attendee') }}](mailto:?subject={{ $forwardSubject }}&body={{ $forwardBody }})

{{ __('Or copy this link:') }}
`{{ $ticket['ticketUrl'] }}`

@endforeach
@endif

{{ __('If you have any questions or need assistance, please contact') }} <a href="mailto:{{ $organizer->getEmail() }}">{{ $organizer->getEmail() }}</a>.

{{ __('Best regards,') }}<br>
{{ $organizer->getName() ?: config('app.name') }}
</x-mail::message>
