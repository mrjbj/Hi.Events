<?php

namespace HiEvents\Http\Request\Order;

use HiEvents\Http\Request\BaseRequest;

class BulkAssignAttendeeSeatInfoRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'seat_info' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'seat_info.max' => __('Table / Seat must be 100 characters or fewer'),
        ];
    }
}
