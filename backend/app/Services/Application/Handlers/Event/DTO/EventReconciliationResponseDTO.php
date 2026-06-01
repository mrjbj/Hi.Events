<?php

namespace HiEvents\Services\Application\Handlers\Event\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Spatie\LaravelData\Attributes\DataCollectionOf;

class EventReconciliationResponseDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $currency,

        // Waterfall: gross − refunds − comps + donations = net expected funds
        public readonly float $gross_sales,
        public readonly float $refunds,
        public readonly float $comps,
        public readonly float $write_offs,
        public readonly float $donations,
        public readonly float $net_expected_funds,

        // Cash position
        public readonly float $total_received,
        public readonly float $collected,
        public readonly float $total_fees,
        public readonly float $net_to_bank,

        /** @var array<int, EventReconciliationChannelDTO> */
        #[DataCollectionOf(EventReconciliationChannelDTO::class)]
        public readonly array $channels,

        public readonly ?string $fees_updated_at,
        public readonly ?int $fees_updated_by_user_id,
    ) {}
}
