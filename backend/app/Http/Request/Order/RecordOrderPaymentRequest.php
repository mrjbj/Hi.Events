<?php

namespace HiEvents\Http\Request\Order;

use HiEvents\DomainObjects\Enums\OrderPaymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordOrderPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        $forgivenessTypes = [OrderPaymentType::COMP->value, OrderPaymentType::WRITE_OFF->value];

        return [
            'type' => ['required', 'string', Rule::in(OrderPaymentType::valuesArray())],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => [
                Rule::requiredIf(fn() => in_array($this->input('type'), $forgivenessTypes, true)),
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => __('A reason is required when comping or writing off a balance'),
        ];
    }
}
