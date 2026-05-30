<?php

namespace HiEvents\Services\Application\Handlers\CheckInList\Public\DTO;

use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\DomainObjects\Enums\OfflinePaymentMethod;
use Spatie\LaravelData\Data;

class AttendeeAndActionDTO extends Data
{
    public function __construct(
        public string                     $public_id,
        public AttendeeCheckInActionType  $action,
        public ?OfflinePaymentMethod      $payment_method = null,
        public ?string                    $payment_reference = null,
    )
    {
    }
}
