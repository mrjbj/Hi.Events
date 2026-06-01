<?php

namespace HiEvents\Http\Request\Order;

use HiEvents\DomainObjects\Enums\OfflinePaymentMethod;
use HiEvents\DomainObjects\Enums\PaymentTransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordOrderPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        $moneyTypes = array_map(static fn (PaymentTransactionType $t) => $t->value, PaymentTransactionType::cashReceiptTypes());
        $forgivenessTypes = array_map(static fn (PaymentTransactionType $t) => $t->value, PaymentTransactionType::compTypes());

        return [
            'transaction_type' => ['required', 'string', Rule::in(PaymentTransactionType::valuesArray())],
            'payment_method' => [
                Rule::requiredIf(fn () => in_array($this->input('transaction_type'), $moneyTypes, true)),
                'nullable',
                'string',
                Rule::in(OfflinePaymentMethod::valuesArray()),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'split_excess_as_donation' => ['nullable', 'boolean'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => [
                Rule::requiredIf(fn () => in_array($this->input('transaction_type'), $forgivenessTypes, true)),
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_method.required' => __('A payment method is required when recording a payment or donation'),
            'note.required' => __('A reason is required when comping or writing off a balance'),
        ];
    }
}
