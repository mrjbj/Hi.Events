<?php

namespace HiEvents\Resources\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\Resources\CheckInList\AttendeeCheckInPublicResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendeeDomainObject
 */
class AttendeeWithCheckInPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $order = $this->getOrder();

        return [
            'id' => $this->getId(),
            'email' => $this->getEmail(),
            'first_name' => $this->getFirstName(),
            'last_name' => $this->getLastName(),
            'public_id' => $this->getPublicId(),
            'product_id' => $this->getProductId(),
            'product_price_id' => $this->getProductPriceId(),
            'status' => $this->getStatus(),
            'locale' => $this->getLocale(),
            'order_id' => $this->getOrderId(),
            'contact_id' => $this->getContactId(),
            'contact_token' => $this->getContactToken(),
            'from_group_purchase' => $this->getFromGroupPurchase(),
            'seat_info' => $this->getSeatInfo(),
            'profile_completion_recommended' => $this->profileCompletionRecommended(),
            'buyer_first_name' => $order?->getFirstName(),
            'buyer_last_name' => $order?->getLastName(),
            'buyer_email' => $order?->getEmail(),
            $this->mergeWhen($this->getCheckIn() !== null, [
                'check_in' => new AttendeeCheckInPublicResource($this->getCheckIn()),
            ]),
        ];
    }

    /**
     * True when this attendee's name fields look like a placeholder — usually
     * because the buyer purchased multiple seats and left the guest details
     * blank. Check-in staff use this to know which attendees still need their
     * details captured at the door.
     */
    private function profileCompletionRecommended(): bool
    {
        return trim((string) $this->getFirstName()) === ''
            || trim((string) $this->getLastName()) === '';
    }
}
