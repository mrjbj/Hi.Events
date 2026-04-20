<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Contact\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ContactPrefillResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly bool $found,
        public readonly ?string $first_name = null,
        public readonly ?string $last_name = null,
        public readonly array $question_answers = [],
        public readonly array $answered_question_ids = [],
    ) {}
}
