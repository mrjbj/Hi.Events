<?php

namespace Tests\Unit\Services\Domain\Order;

use HiEvents\DomainObjects\Enums\OrderPaymentType;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderPaymentDomainObject;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Repository\Interfaces\OrderPaymentRepositoryInterface;
use HiEvents\Services\Domain\Order\OrderBalanceService;
use Mockery;
use Tests\TestCase;

class OrderBalanceServiceTest extends TestCase
{
    private OrderBalanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrderBalanceService(
            Mockery::mock(OrderPaymentRepositoryInterface::class),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_unpaid_order_owes_full_amount(): void
    {
        $balance = $this->service->calculate($this->order(100.0), collect());

        $this->assertSame(100.0, $balance->amountOwed);
        $this->assertSame(0.0, $balance->amountCollected);
        $this->assertSame(100.0, $balance->balance);
        $this->assertFalse($balance->isSettled);
        $this->assertSame(0.0, $balance->overpaid);
    }

    public function test_exact_cash_payment_settles_order(): void
    {
        $balance = $this->service->calculate($this->order(100.0), collect([
            $this->payment(OrderPaymentType::CASH, 100.0),
        ]));

        $this->assertSame(0.0, $balance->balance);
        $this->assertSame(100.0, $balance->amountCollected);
        $this->assertTrue($balance->isSettled);
        $this->assertSame(0.0, $balance->overpaid);
    }

    public function test_partial_payment_leaves_outstanding_balance(): void
    {
        $balance = $this->service->calculate($this->order(100.0), collect([
            $this->payment(OrderPaymentType::CASH, 15.0),
        ]));

        $this->assertSame(85.0, $balance->balance);
        $this->assertSame(15.0, $balance->amountCollected);
        $this->assertFalse($balance->isSettled);
    }

    public function test_comp_remainder_settles_without_counting_as_cash(): void
    {
        $balance = $this->service->calculate($this->order(100.0), collect([
            $this->payment(OrderPaymentType::CASH, 15.0),
            $this->payment(OrderPaymentType::COMP, 85.0),
        ]));

        $this->assertSame(0.0, $balance->balance);
        $this->assertTrue($balance->isSettled);
        $this->assertSame(15.0, $balance->amountCollected);
        $this->assertSame(85.0, $balance->totalComps);
    }

    public function test_overpayment_surfaces_as_donation(): void
    {
        $balance = $this->service->calculate($this->order(100.0), collect([
            $this->payment(OrderPaymentType::CASH, 120.0),
        ]));

        $this->assertSame(-20.0, $balance->balance);
        $this->assertTrue($balance->isSettled);
        $this->assertSame(120.0, $balance->amountCollected);
        $this->assertSame(20.0, $balance->overpaid);
    }

    public function test_stripe_receipt_settles_order(): void
    {
        $balance = $this->service->calculate($this->order(100.0, stripeMinorUnits: 10000), collect());

        $this->assertSame(0.0, $balance->balance);
        $this->assertSame(100.0, $balance->amountCollected);
        $this->assertTrue($balance->isSettled);
    }

    public function test_refund_increases_balance_and_reduces_collected(): void
    {
        $balance = $this->service->calculate(
            $this->order(100.0, totalRefunded: 100.0, stripeMinorUnits: 10000),
            collect(),
        );

        $this->assertSame(0.0, $balance->amountCollected);
        $this->assertSame(100.0, $balance->totalRefunded);
        $this->assertSame(100.0, $balance->balance);
        $this->assertFalse($balance->isSettled);
    }

    private function order(float $gross, float $totalRefunded = 0.0, ?int $stripeMinorUnits = null): OrderDomainObject
    {
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(1);
        $order->shouldReceive('getTotalGross')->andReturn($gross);
        $order->shouldReceive('getTotalRefunded')->andReturn($totalRefunded);

        if ($stripeMinorUnits !== null) {
            $stripe = Mockery::mock(StripePaymentDomainObject::class);
            $stripe->shouldReceive('getAmountReceived')->andReturn($stripeMinorUnits);
            $order->shouldReceive('getStripePayment')->andReturn($stripe);
        } else {
            $order->shouldReceive('getStripePayment')->andReturn(null);
        }

        return $order;
    }

    private function payment(OrderPaymentType $type, float $amount): OrderPaymentDomainObject
    {
        $payment = Mockery::mock(OrderPaymentDomainObject::class);
        $payment->shouldReceive('getType')->andReturn($type->value);
        $payment->shouldReceive('getAmount')->andReturn($amount);
        return $payment;
    }
}
