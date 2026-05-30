<?php

namespace HiEvents\Services\Domain\Order;

use HiEvents\DataTransferObjects\OrderBalanceDTO;
use HiEvents\DomainObjects\Enums\OrderPaymentType;
use HiEvents\DomainObjects\Generated\OrderPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderPaymentDomainObject;
use HiEvents\Repository\Interfaces\OrderPaymentRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Computes an order's outstanding balance from the payments ledger.
 *
 * The order is the immutable receivable (total_gross). Balance is derived from
 * three sources and never written back onto the order:
 *
 *   balance = owed − cash receipts − stripe receipts − comps + refunds
 *
 * Stripe receipts come from the confirmed stripe_payments.amount_received (minor
 * units), NOT from the mere presence of a stripe_payments row. Refunds use the
 * authoritative orders.total_refunded rollup (Stripe or offline) — refunds are
 * never ledger rows, so the balance cannot double-count them.
 */
class OrderBalanceService
{
    public function __construct(
        private readonly OrderPaymentRepositoryInterface $orderPaymentRepository,
    )
    {
    }

    public function getBalanceForOrder(OrderDomainObject $order): OrderBalanceDTO
    {
        $payments = $this->orderPaymentRepository->findWhere([
            OrderPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
        ]);

        return $this->calculate($order, $payments);
    }

    /**
     * @param Collection<int, OrderPaymentDomainObject> $orderPayments
     */
    public function calculate(OrderDomainObject $order, Collection $orderPayments): OrderBalanceDTO
    {
        $owed = round((float)$order->getTotalGross(), 2);

        $cashTypes = array_map(static fn(OrderPaymentType $t) => $t->value, OrderPaymentType::cashReceiptTypes());
        $compTypes = array_map(static fn(OrderPaymentType $t) => $t->value, OrderPaymentType::compTypes());

        $cashReceipts = 0.0;
        $comps = 0.0;

        foreach ($orderPayments as $payment) {
            $amount = round((float)$payment->getAmount(), 2);
            $type = $payment->getType();

            if (in_array($type, $compTypes, true)) {
                $comps += $amount;
            } elseif (in_array($type, $cashTypes, true)) {
                $cashReceipts += $amount;
            }
        }

        $stripePayment = $order->getStripePayment();
        $stripeReceipts = $stripePayment && $stripePayment->getAmountReceived()
            ? round($stripePayment->getAmountReceived() / 100, 2)
            : 0.0;

        $refunds = round((float)$order->getTotalRefunded(), 2);
        $grossReceipts = round($cashReceipts + $stripeReceipts, 2);
        $collected = round($grossReceipts - $refunds, 2);
        $balance = round($owed - $grossReceipts - $comps + $refunds, 2);
        $overpaid = max(0.0, round($collected - $owed, 2));

        return new OrderBalanceDTO(
            amountOwed: $owed,
            amountCollected: $collected,
            totalComps: round($comps, 2),
            totalRefunded: $refunds,
            balance: $balance,
            overpaid: $overpaid,
            isSettled: $balance <= 0.0,
        );
    }
}
