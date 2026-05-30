<?php

namespace HiEvents\Services\Application\Handlers\Order\DTO;

use HiEvents\DataTransferObjects\BaseDTO;
use HiEvents\DomainObjects\Enums\OfflinePaymentMethod;

class MarkOrderAsPaidDTO extends BaseDTO
{
    public function __construct(
        public readonly int                  $eventId,
        public readonly int                  $orderId,
        public readonly OfflinePaymentMethod $paymentMethod,
        public readonly ?string              $paymentReference = null,
        public readonly ?float               $amountReceived = null,
        public readonly ?string              $recordedByIp = null,
    )
    {
    }
}
