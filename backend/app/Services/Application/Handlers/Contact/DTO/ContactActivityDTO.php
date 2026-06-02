<?php

namespace HiEvents\Services\Application\Handlers\Contact\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Spatie\LaravelData\Attributes\DataCollectionOf;

class ContactActivityDTO extends BaseDataObject
{
    public function __construct(
        /** @var ContactAttendedEventDTO[] */
        #[DataCollectionOf(ContactAttendedEventDTO::class)]
        public readonly array $events,
        /** @var ContactOrderSummaryDTO[] */
        #[DataCollectionOf(ContactOrderSummaryDTO::class)]
        public readonly array $orders,
    ) {}
}
