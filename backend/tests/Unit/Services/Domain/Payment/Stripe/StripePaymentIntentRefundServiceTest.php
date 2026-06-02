<?php

namespace Tests\Unit\Services\Domain\Payment\Stripe;

use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentRefundService;
use HiEvents\Values\MoneyValue;
use Illuminate\Config\Repository;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Stripe\Refund;
use Stripe\Service\RefundService;
use Stripe\StripeClient;
use Tests\TestCase;

class StripePaymentIntentRefundServiceTest extends TestCase
{
    private LoggerInterface $logger;
    private Repository $config;
    private StripePaymentIntentRefundService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = m::mock(LoggerInterface::class);
        $this->config = m::mock(Repository::class);

        $this->service = new StripePaymentIntentRefundService($this->config, $this->logger);
    }

    private function refundAndCaptureOpts(StripePaymentDomainObject $payment): array
    {
        $capturedOpts = null;

        $refundsMock = m::mock(RefundService::class);
        $refundsMock->shouldReceive('create')
            ->once()
            ->with(
                m::type('array'),
                m::on(function ($opts) use (&$capturedOpts) {
                    $capturedOpts = $opts;
                    return true;
                })
            )
            ->andReturn(new Refund());

        $stripeClient = m::mock(StripeClient::class);
        $stripeClient->refunds = $refundsMock;

        $this->service->refundPayment(
            MoneyValue::fromFloat(10.00, 'USD'),
            $payment,
            $stripeClient,
        );

        return $capturedOpts;
    }

    public function testRefundRoutesThroughConnectedAccountWhenPaymentHasOne(): void
    {
        $payment = (new StripePaymentDomainObject())
            ->setPaymentIntentId('pi_123')
            ->setConnectedAccountId('acct_123');

        $opts = $this->refundAndCaptureOpts($payment);

        $this->assertSame(['stripe_account' => 'acct_123'], $opts);
    }

    public function testRefundUsesPlatformAccountWhenPaymentHasNoConnectedAccountInNonSaasMode(): void
    {
        $this->config->shouldReceive('get')->with('app.saas_mode_enabled')->andReturn(false);

        $payment = (new StripePaymentDomainObject())
            ->setPaymentIntentId('pi_123')
            ->setConnectedAccountId(null);

        $opts = $this->refundAndCaptureOpts($payment);

        $this->assertSame([], $opts);
    }

    public function testHistoricalDirectChargeStillRefundsWhenSaasModeEnabled(): void
    {
        $this->config->shouldReceive('get')->with('app.saas_mode_enabled')->andReturn(true);
        $this->logger->shouldReceive('warning')->once();

        $payment = (new StripePaymentDomainObject())
            ->setPaymentIntentId('pi_legacy')
            ->setConnectedAccountId(null);

        $opts = $this->refundAndCaptureOpts($payment);

        $this->assertSame([], $opts);
    }
}
