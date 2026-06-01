<?php

namespace HiEvents\Resources\Order;

use HiEvents\DomainObjects\OrderPaymentDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin OrderPaymentDomainObject
 */
class OrderPaymentResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'reverses_payment_id' => $this->getReversesPaymentId(),
            'transaction_type' => $this->getTransactionType(),
            'payment_method' => $this->getPaymentMethod(),
            'amount' => $this->getAmount(),
            'currency' => $this->getCurrency(),
            'reference' => $this->getReference(),
            'note' => $this->getNote(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
