<?php

namespace Tests\Unit\Http\Request\Order;

use HiEvents\Http\Request\Order\ReverseOrderPaymentRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ReverseOrderPaymentRequestTest extends TestCase
{
    private function validate(array $data): \Illuminate\Validation\Validator
    {
        $request = new ReverseOrderPaymentRequest;
        $request->merge($data);

        return Validator::make($request->all(), $request->rules(), $request->messages());
    }

    public function test_reversal_requires_a_reason(): void
    {
        $validator = $this->validate([]);

        $this->assertTrue($validator->errors()->has('note'));
    }

    public function test_reversal_passes_with_a_reason(): void
    {
        $validator = $this->validate([
            'note' => 'Entered the wrong amount',
        ]);

        $this->assertFalse($validator->errors()->has('note'));
    }
}
