<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrderPaymentDomainObject;
use HiEvents\Models\OrderPayment;
use HiEvents\Repository\Interfaces\OrderPaymentRepositoryInterface;

/**
 * @extends BaseRepository<OrderPaymentDomainObject>
 */
class OrderPaymentRepository extends BaseRepository implements OrderPaymentRepositoryInterface
{
    protected function getModel(): string
    {
        return OrderPayment::class;
    }

    public function getDomainObject(): string
    {
        return OrderPaymentDomainObject::class;
    }
}
