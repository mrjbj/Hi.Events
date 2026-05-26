<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrderPaymentAdjustmentDomainObject;
use HiEvents\Models\OrderPaymentAdjustment;
use HiEvents\Repository\Interfaces\OrderPaymentAdjustmentRepositoryInterface;

/**
 * @extends BaseRepository<OrderPaymentAdjustmentDomainObject>
 */
class OrderPaymentAdjustmentRepository extends BaseRepository implements OrderPaymentAdjustmentRepositoryInterface
{
    protected function getModel(): string
    {
        return OrderPaymentAdjustment::class;
    }

    public function getDomainObject(): string
    {
        return OrderPaymentAdjustmentDomainObject::class;
    }
}
