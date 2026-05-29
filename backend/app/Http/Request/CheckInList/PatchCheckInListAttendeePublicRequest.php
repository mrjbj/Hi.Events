<?php

namespace HiEvents\Http\Request\CheckInList;

use HiEvents\Http\Request\BaseRequest;

class PatchCheckInListAttendeePublicRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:100', 'min:1'],
            'last_name' => ['sometimes', 'string', 'max:100', 'min:1'],
            'email' => ['sometimes', 'email', 'max:100'],
            'seat_info' => ['sometimes', 'nullable', 'string', 'max:100'],
            'confirm_at_checkin' => ['sometimes', 'boolean'],
            'notify_email_change' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $present = array_filter(
                    [
                        $this->input('first_name'),
                        $this->input('last_name'),
                        $this->input('email'),
                        $this->input('seat_info'),
                    ],
                    fn ($v) => $v !== null && $v !== ''
                );
                if (empty($present) && ! $this->has('confirm_at_checkin')) {
                    $validator->errors()->add('first_name', __('At least one of first_name, last_name, email, seat_info, or confirm_at_checkin is required.'));
                }
            },
        ];
    }
}
