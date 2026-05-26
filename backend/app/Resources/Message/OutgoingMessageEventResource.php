<?php

namespace HiEvents\Resources\Message;

use HiEvents\DomainObjects\OutgoingMessageEventDomainObject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OutgoingMessageEventDomainObject
 */
class OutgoingMessageEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'event_type' => $this->getEventType(),
            'event_subtype' => $this->getEventSubtype(),
            'provider' => $this->getProvider(),
            'provider_message_id' => $this->getProviderMessageId(),
            'occurred_at' => $this->getOccurredAt(),
            'created_at' => $this->getCreatedAt(),
            'raw_payload' => $this->getRawPayload(),
        ];
    }
}
