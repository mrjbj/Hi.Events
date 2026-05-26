<?php

namespace HiEvents\Http\Request\CheckInList;

use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\DomainObjects\Enums\OfflinePaymentMethod;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class CreateAttendeeCheckInPublicRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'attendees' => ['required', 'array'],
            'attendees.*.public_id' => ['required', 'string'],
            'attendees.*.action' => ['required', 'string', Rule::in(AttendeeCheckInActionType::valuesArray())],
            'attendees.*.payment_method' => [
                'nullable',
                'required_if:attendees.*.action,' . AttendeeCheckInActionType::CHECK_IN_AND_MARK_ORDER_AS_PAID->value,
                'string',
                Rule::in(OfflinePaymentMethod::valuesArray()),
            ],
            'attendees.*.payment_reference' => ['nullable', 'string', 'max:255'],
            'attendees.*.collected_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
