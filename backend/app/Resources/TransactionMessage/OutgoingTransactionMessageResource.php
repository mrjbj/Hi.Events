<?php

namespace HiEvents\Resources\TransactionMessage;

use HiEvents\DomainObjects\Enums\TransactionalEmailType;
use HiEvents\DomainObjects\OutgoingTransactionMessageDomainObject;
use HiEvents\Helper\Url;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OutgoingTransactionMessageDomainObject
 */
class OutgoingTransactionMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'event_id' => $this->getEventId(),
            'order_id' => $this->getOrderId(),
            'attendee_id' => $this->getAttendeeId(),
            'email_type' => $this->getEmailType(),
            'recipient' => $this->getRecipient(),
            'subject' => $this->getSubject(),
            'status' => $this->getStatus(),
            'resolved_at' => $this->getResolvedAt(),
            'resolution_type' => $this->getResolutionType(),
            'retry_for_id' => $this->getRetryForId(),
            'retry_count' => $this->getRetryCount(),
            'latest_retry_recipient' => $this->getLatestRetryRecipient(),
            'latest_retry_status' => $this->getLatestRetryStatus(),
            'original_recipient' => $this->getOriginalRecipient(),
            'original_status' => $this->getOriginalStatus(),
            'created_at' => $this->getCreatedAt(),
            'updated_at' => $this->getUpdatedAt(),
            'event_count' => $this->getEventCount(),
            'cta_url' => $this->buildCtaUrl(),
        ];
    }

    private function buildCtaUrl(): ?string
    {
        $eventId = $this->getEventId();
        if ($eventId === null) {
            return null;
        }

        return match ($this->getEmailType()) {
            TransactionalEmailType::ORDER_SUMMARY->value => $this->getOrderShortId()
                ? sprintf(Url::getFrontEndUrlFromConfig(Url::ORDER_SUMMARY), $eventId, $this->getOrderShortId())
                : null,
            TransactionalEmailType::ATTENDEE_TICKET->value => $this->getAttendeeShortId()
                ? sprintf(Url::getFrontEndUrlFromConfig(Url::ATTENDEE_TICKET), $eventId, $this->getAttendeeShortId())
                : null,
            default => null,
        };
    }
}
