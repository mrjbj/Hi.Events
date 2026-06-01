<?php

namespace HiEvents\Services\Application\Handlers\Event\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class EventReconciliationChannelDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $channel,
        public readonly float $sales,
        public readonly float $donations,
        public readonly float $refunds,
        public readonly float $fee,
        public readonly float $net,
        public readonly float $share,
    ) {}
}
