<?php

namespace Tests\Unit\Services\Domain\Event;

use HiEvents\DomainObjects\Enums\PaymentChannel;
use HiEvents\Repository\Interfaces\EventChannelFeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Event\EventReconciliationService;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Tests\TestCase;

class EventReconciliationServiceTest extends TestCase
{
    private EventReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EventReconciliationService(
            Mockery::mock(DatabaseManager::class),
            Mockery::mock(EventRepositoryInterface::class),
            Mockery::mock(EventChannelFeeRepositoryInterface::class),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_full_breakdown_reconciles(): void
    {
        $dto = $this->service->assemble(
            currency: 'USD',
            grossTotal: 19250.0,
            salesByChannel: [
                PaymentChannel::STRIPE->value => 12400.0,
                PaymentChannel::SQUARE->value => 3100.0,
                PaymentChannel::CASH->value => 1985.0,
                PaymentChannel::CHECK->value => 400.0,
            ],
            donationsByChannel: [
                PaymentChannel::STRIPE->value => 300.0,
                PaymentChannel::SQUARE->value => 100.0,
            ],
            refundsByChannel: [
                PaymentChannel::STRIPE->value => 680.0,
                PaymentChannel::SQUARE->value => 300.0,
            ],
            comps: 240.0,
            writeOffs: 0.0,
            feesByChannel: [
                PaymentChannel::STRIPE->value => 390.0,
                PaymentChannel::SQUARE->value => 95.0,
            ],
        );

        // Headline waterfall: 19250 − 980 − 240 + 400
        $this->assertSame(19250.0, $dto->gross_sales);
        $this->assertSame(980.0, $dto->refunds);
        $this->assertSame(240.0, $dto->comps);
        $this->assertSame(0.0, $dto->write_offs);
        $this->assertSame(400.0, $dto->donations);
        $this->assertSame(18430.0, $dto->net_expected_funds);

        // Cash position
        $this->assertSame(18285.0, $dto->total_received);
        $this->assertSame(17305.0, $dto->collected);
        $this->assertSame(485.0, $dto->total_fees);
        $this->assertSame(16820.0, $dto->net_to_bank);

        // No expenses recorded → gain/(loss) equals net to bank.
        $this->assertSame(0.0, $dto->expenses);
        $this->assertSame(16820.0, $dto->gain_loss);

        // Four active channels, in display order
        $this->assertCount(4, $dto->channels);
        $this->assertSame(PaymentChannel::STRIPE->value, $dto->channels[0]->channel);
        $this->assertSame(11630.0, $dto->channels[0]->net);
        $this->assertSame(PaymentChannel::SQUARE->value, $dto->channels[1]->channel);
        $this->assertSame(2805.0, $dto->channels[1]->net);
        $this->assertSame(1985.0, $dto->channels[2]->net);
        $this->assertSame(400.0, $dto->channels[3]->net);

        // Per-channel nets reconcile to net-to-bank
        $this->assertEqualsWithDelta(
            $dto->net_to_bank,
            array_sum(array_map(static fn ($c) => $c->net, $dto->channels)),
            0.001,
        );

        // Shares sum to ~1
        $this->assertEqualsWithDelta(1.0, array_sum(array_map(static fn ($c) => $c->share, $dto->channels)), 0.001);
    }

    public function test_expenses_yield_gain(): void
    {
        $dto = $this->service->assemble(
            currency: 'USD',
            grossTotal: 1000.0,
            salesByChannel: [PaymentChannel::CASH->value => 1000.0],
            donationsByChannel: [],
            refundsByChannel: [],
            comps: 0.0,
            writeOffs: 0.0,
            feesByChannel: [],
            expenses: 150.0,
        );

        // net_to_bank 1000 − expenses 150 = gain 850
        $this->assertSame(1000.0, $dto->net_to_bank);
        $this->assertSame(150.0, $dto->expenses);
        $this->assertSame(850.0, $dto->gain_loss);
    }

    public function test_expenses_exceeding_net_yield_a_loss(): void
    {
        $dto = $this->service->assemble(
            currency: 'USD',
            grossTotal: 100.0,
            salesByChannel: [PaymentChannel::CASH->value => 100.0],
            donationsByChannel: [],
            refundsByChannel: [],
            comps: 0.0,
            writeOffs: 0.0,
            feesByChannel: [],
            expenses: 250.0,
        );

        $this->assertSame(100.0, $dto->net_to_bank);
        $this->assertSame(-150.0, $dto->gain_loss);
    }

    public function test_empty_event_is_all_zero(): void
    {
        $dto = $this->service->assemble(
            currency: 'USD',
            grossTotal: 0.0,
            salesByChannel: [],
            donationsByChannel: [],
            refundsByChannel: [],
            comps: 0.0,
            writeOffs: 0.0,
            feesByChannel: [],
        );

        $this->assertSame(0.0, $dto->net_expected_funds);
        $this->assertSame(0.0, $dto->net_to_bank);
        $this->assertSame([], $dto->channels);
    }

    public function test_fee_only_channel_is_shown_with_negative_net(): void
    {
        $dto = $this->service->assemble(
            currency: 'USD',
            grossTotal: 0.0,
            salesByChannel: [],
            donationsByChannel: [],
            refundsByChannel: [],
            comps: 0.0,
            writeOffs: 0.0,
            feesByChannel: [PaymentChannel::OTHER->value => 10.0],
        );

        $this->assertCount(1, $dto->channels);
        $this->assertSame(PaymentChannel::OTHER->value, $dto->channels[0]->channel);
        $this->assertSame(-10.0, $dto->channels[0]->net);
        $this->assertSame(10.0, $dto->total_fees);
        $this->assertSame(-10.0, $dto->net_to_bank);
    }

    public function test_comps_and_write_offs_reduce_expected_without_touching_cash(): void
    {
        $dto = $this->service->assemble(
            currency: 'USD',
            grossTotal: 1000.0,
            salesByChannel: [
                PaymentChannel::CASH->value => 600.0,
            ],
            donationsByChannel: [],
            refundsByChannel: [],
            comps: 250.0,
            writeOffs: 150.0,
            feesByChannel: [],
        );

        // 1000 − 0 − 250 − 150 + 0 = 600, which equals cash collected
        $this->assertSame(250.0, $dto->comps);
        $this->assertSame(150.0, $dto->write_offs);
        $this->assertSame(600.0, $dto->net_expected_funds);
        $this->assertSame(600.0, $dto->collected);
    }
}
