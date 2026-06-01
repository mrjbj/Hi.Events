<?php

namespace Tests\Unit\Http\Request\Order;

use HiEvents\DomainObjects\Enums\OfflinePaymentMethod;
use HiEvents\DomainObjects\Enums\PaymentTransactionType;
use HiEvents\Http\Request\Order\RecordOrderPaymentRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class RecordOrderPaymentRequestTest extends TestCase
{
    private function validate(array $data): \Illuminate\Validation\Validator
    {
        $request = new RecordOrderPaymentRequest;
        $request->merge($data);

        return Validator::make($request->all(), $request->rules(), $request->messages());
    }

    public function test_comp_requires_a_reason(): void
    {
        $validator = $this->validate([
            'transaction_type' => PaymentTransactionType::COMP->value,
            'amount' => 50,
        ]);

        $this->assertTrue($validator->errors()->has('note'));
    }

    public function test_write_off_requires_a_reason(): void
    {
        $validator = $this->validate([
            'transaction_type' => PaymentTransactionType::WRITE_OFF->value,
            'amount' => 50,
        ]);

        $this->assertTrue($validator->errors()->has('note'));
    }

    public function test_comp_passes_with_a_reason(): void
    {
        $validator = $this->validate([
            'transaction_type' => PaymentTransactionType::COMP->value,
            'amount' => 50,
            'note' => 'Board-approved sponsor comp',
        ]);

        $this->assertFalse($validator->errors()->has('note'));
    }

    public function test_comp_does_not_require_a_payment_method(): void
    {
        $validator = $this->validate([
            'transaction_type' => PaymentTransactionType::COMP->value,
            'amount' => 50,
            'note' => 'Board-approved sponsor comp',
        ]);

        $this->assertFalse($validator->errors()->has('payment_method'));
    }

    public function test_payment_requires_a_method(): void
    {
        $validator = $this->validate([
            'transaction_type' => PaymentTransactionType::PAYMENT->value,
            'amount' => 50,
        ]);

        $this->assertTrue($validator->errors()->has('payment_method'));
    }

    public function test_payment_with_method_passes(): void
    {
        $validator = $this->validate([
            'transaction_type' => PaymentTransactionType::PAYMENT->value,
            'payment_method' => OfflinePaymentMethod::CASH->value,
            'amount' => 50,
        ]);

        $this->assertFalse($validator->errors()->has('payment_method'));
        $this->assertFalse($validator->errors()->has('note'));
    }

    public function test_donation_requires_a_method(): void
    {
        $validator = $this->validate([
            'transaction_type' => PaymentTransactionType::DONATION->value,
            'amount' => 25,
        ]);

        $this->assertTrue($validator->errors()->has('payment_method'));
    }
}
