<?php

namespace HiEvents\Services\Domain\Contact\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\AttendeeContactResolutionAction;

class AttendeeContactResolutionDTO extends BaseDataObject
{
    public function __construct(
        public readonly AttendeeContactResolutionAction $action,
        /** The attendee's contact_id after resolution (may be unchanged). */
        public readonly ?int $contactId,
        /** True when contact_id was re-pointed (caller should mint a fresh contact token). */
        public readonly bool $linkChanged,
    ) {}
}
