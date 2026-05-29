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
            'confirm_at_checkin' => $this->getConfirmAtCheckin(),
            'profile_completion_recommended' => $this->profileCompletionRecommended(),
            'buyer_first_name' => $order?->getFirstName(),
            'buyer_last_name' => $order?->getLastName(),
            'buyer_email' => $order?->getEmail(),
            'order_total_gross' => $order?->getTotalGross(),
            'order_currency' => $order?->getCurrency(),
            $this->mergeWhen($this->getCheckIn() !== null, [
                'check_in' => new AttendeeCheckInPublicResource($this->getCheckIn()),
            ]),
        ];
    }

    /**
     * True when check-in staff should be prompted to capture this attendee's
     * details at the door. Driven by the stored confirm_at_checkin flag, with a
     * fallback to the legacy heuristic (blank first/last name) for rows created
     * before the flag existed.
     */
    private function profileCompletionRecommended(): bool
    {
        return $this->getConfirmAtCheckin()
            || trim((string) $this->getFirstName()) === ''
            || trim((string) $this->getLastName()) === '';
    }
}
