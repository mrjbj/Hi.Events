<?php

namespace HiEvents\Services\Application\Handlers\Order\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\OrderPaymentType;

class RecordOrderPaymentDTO extends BaseDataObject
{
    public function __construct(
        public readonly int              $eventId,
        public readonly int              $orderId,
        public readonly OrderPaymentType $type,
        public readonly float            $amount,
        public readonly ?string          $reference = null,
        public readonly ?string          $note = null,
        public readonly ?int             $recordedByUserId = null,
        public readonly ?string          $recordedByIp = null,
    )
    {
    }
}
