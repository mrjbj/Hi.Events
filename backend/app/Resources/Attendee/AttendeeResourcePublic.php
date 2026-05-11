<?php

namespace HiEvents\Resources\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\Resources\Product\ProductMinimalResourcePublic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendeeDomainObject
 */
class AttendeeResourcePublic extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'email' => $this->getEmail(),
            'status' => $this->getStatus(),
            'first_name' => $this->getFirstName(),
            'last_name' => $this->getLastName(),
            'public_id' => $this->getPublicId(),
            'short_id' => $this->getShortId(),
            'product_id' => $this->getProductId(),
            'product_price_id' => $this->getProductPriceId(),
            'product' => $this->when((bool) $this->getProduct(), fn () => new ProductMinimalResourcePublic($this->getProduct())),
            'locale' => $this->getLocale(),
            'contact_id' => $this->getContactId(),
            'contact_token' => $this->getContactToken(),
            'profile_completion_recommended' => $this->profileCompletionRecommended(),
        ];
    }

    /**
     * True when this attendee's name fields look like a placeholder — usually
     * because the buyer purchased multiple seats and left the guest details
     * blank. The public ticket page uses this to surface a "please confirm
     * your details" prompt and pre-open the profile panel.
     */
    private function profileCompletionRecommended(): bool
    {
        return trim((string) $this->getFirstName()) === ''
            || trim((string) $this->getLastName()) === '';
    }
}
