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
                if (empty($present)) {
                    $validator->errors()->add('first_name', __('At least one of first_name, last_name, email, or seat_info is required.'));
                }
            },
        ];
    }
}
