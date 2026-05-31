<?php

namespace HiEvents\Http\Request\Order;

use Illuminate\Foundation\Http\FormRequest;

class ReverseOrderPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => __('A reason is required to reverse a payment'),
        ];
    }
}
