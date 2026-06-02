<?php

namespace HiEvents\Services\Application\Handlers\Contact\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ContactAttendedEventDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $start_date,
        public readonly ?string $status,
        public readonly int $tickets_count,
    ) {}
}
