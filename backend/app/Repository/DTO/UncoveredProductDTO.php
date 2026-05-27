<?php

namespace HiEvents\Repository\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class UncoveredProductDTO extends BaseDataObject
{
    public function __construct(
        public int $product_id,
        public string $title,
        public int $attendee_count,
    ) {}
}
