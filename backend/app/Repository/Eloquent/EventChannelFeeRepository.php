<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\EventChannelFeeDomainObject;
use HiEvents\Models\EventChannelFee;
use HiEvents\Repository\Interfaces\EventChannelFeeRepositoryInterface;

/**
 * @extends BaseRepository<EventChannelFeeDomainObject>
 */
class EventChannelFeeRepository extends BaseRepository implements EventChannelFeeRepositoryInterface
{
    protected function getModel(): string
    {
        return EventChannelFee::class;
    }

    public function getDomainObject(): string
    {
        return EventChannelFeeDomainObject::class;
    }
}
