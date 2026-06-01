<?php

namespace HiEvents\Http\Request\Event;

use HiEvents\DomainObjects\Enums\PaymentChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertEventChannelFeesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fees' => ['required', 'array'],
            'fees.*.channel' => ['required', 'string', Rule::in(PaymentChannel::valuesArray())],
            'fees.*.fee_amount' => ['required', 'numeric', 'gte:0'],
            'fees.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
