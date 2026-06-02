<?php

namespace HiEvents\Services\Domain\Payment\Stripe;

use Brick\Math\Exception\MathException;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Values\MoneyValue;
use Illuminate\Config\Repository;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;
use Stripe\StripeClient;

class StripePaymentIntentRefundService
{
    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ApiErrorException
     * @throws MathException
     *
     * @todo - catch and handle stripe errors
     */
    public function refundPayment(
        MoneyValue $amount,
        StripePaymentDomainObject $payment,
        StripeClient $stripeClient,
    ): Refund {
        return $stripeClient->refunds->create(
            params: [
                'payment_intent' => $payment->getPaymentIntentId(),
                'amount' => $amount->toMinorUnit(),
            ],
            opts: $this->getStripeAccountData($payment),
        );
    }

    private function getStripeAccountData(StripePaymentDomainObject $payment): array
    {
        $connectedAccountId = $payment->getConnectedAccountId();

        if ($connectedAccountId !== null) {
            return [
                'stripe_account' => $connectedAccountId,
            ];
        }

        if ($this->config->get('app.saas_mode_enabled')) {
            $this->logger->warning(
                'Refunding a payment without a connected account while saas_mode_enabled is true; refunding via the platform account.',
                ['payment_intent_id' => $payment->getPaymentIntentId()]
            );
        }

        return [];
    }
}
