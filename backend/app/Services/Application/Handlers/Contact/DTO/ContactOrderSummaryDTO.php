<?php

namespace HiEvents\Services\Application\Handlers\Contact\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ContactOrderSummaryDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $short_id,
        public readonly ?string $public_id,
        public readonly ?string $status,
        public readonly ?string $payment_status,
        public readonly ?string $refund_status,
        public readonly float $total_gross,
        public readonly ?string $currency,
        public readonly ?string $created_at,
        public readonly int $event_id,
        public readonly ?string $event_title,
    ) {}
}
