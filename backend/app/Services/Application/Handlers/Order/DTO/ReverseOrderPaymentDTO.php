<?php

namespace HiEvents\Services\Application\Handlers\Order\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ReverseOrderPaymentDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly int $orderId,
        public readonly int $paymentId,
        public readonly string $note,
        public readonly ?int $recordedByUserId = null,
        public readonly ?string $recordedByIp = null,
    ) {}
}
