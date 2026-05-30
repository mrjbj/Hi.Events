<?php

namespace Tests\Unit\Http\Request\Order;

use HiEvents\DomainObjects\Enums\OrderPaymentType;
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
            'type' => OrderPaymentType::COMP->value,
            'amount' => 50,
        ]);

        $this->assertTrue($validator->errors()->has('note'));
    }

    public function test_write_off_requires_a_reason(): void
    {
        $validator = $this->validate([
            'type' => OrderPaymentType::WRITE_OFF->value,
            'amount' => 50,
        ]);

        $this->assertTrue($validator->errors()->has('note'));
    }

    public function test_comp_passes_with_a_reason(): void
    {
        $validator = $this->validate([
            'type' => OrderPaymentType::COMP->value,
            'amount' => 50,
            'note' => 'Board-approved sponsor comp',
        ]);

        $this->assertFalse($validator->errors()->has('note'));
    }

    public function test_cash_receipt_does_not_require_a_reason(): void
    {
        $validator = $this->validate([
            'type' => OrderPaymentType::CASH->value,
            'amount' => 50,
        ]);

        $this->assertFalse($validator->errors()->has('note'));
    }
}
