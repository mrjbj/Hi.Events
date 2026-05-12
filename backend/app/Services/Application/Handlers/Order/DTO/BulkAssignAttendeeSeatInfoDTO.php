<?php

namespace HiEvents\Services\Application\Handlers\Order\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class BulkAssignAttendeeSeatInfoDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly int $orderId,
        public readonly ?string $seatInfo,
    ) {}
}
