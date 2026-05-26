<?php

namespace HiEvents\Resources\Order;

use HiEvents\DomainObjects\OrderPaymentAdjustmentDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin OrderPaymentAdjustmentDomainObject
 */
class OrderPaymentAdjustmentResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'original_total_gross' => $this->getOriginalTotalGross(),
            'original_total_before_additions' => $this->getOriginalTotalBeforeAdditions(),
            'original_total_tax' => $this->getOriginalTotalTax(),
            'original_total_fee' => $this->getOriginalTotalFee(),
            'adjusted_total_gross' => $this->getAdjustedTotalGross(),
            'payment_method' => $this->getPaymentMethod(),
            'payment_reference' => $this->getPaymentReference(),
            'adjusted_by_user_id' => $this->getAdjustedByUserId(),
            'adjusted_by_ip' => $this->getAdjustedByIp(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
