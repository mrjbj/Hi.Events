<?php

namespace HiEvents\Http\Request\Order;

use HiEvents\DomainObjects\Enums\OfflinePaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarkOrderAsPaidRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'string', Rule::in(OfflinePaymentMethod::valuesArray())],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'collected_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
