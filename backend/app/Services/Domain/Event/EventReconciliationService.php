<?php

namespace HiEvents\Services\Domain\Event;

use HiEvents\DomainObjects\Enums\PaymentChannel;
use HiEvents\DomainObjects\Enums\PaymentTransactionType;
use HiEvents\DomainObjects\EventChannelFeeDomainObject;
use HiEvents\DomainObjects\Generated\EventChannelFeeDomainObjectAbstract;
use HiEvents\Repository\Interfaces\EventChannelFeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\EventReconciliationChannelDTO;
use HiEvents\Services\Application\Handlers\Event\DTO\EventReconciliationResponseDTO;
use Illuminate\Database\DatabaseManager;

/**
 * Reconciles all money for an event from the payments ledger, refunds and Stripe
 * receipts, then breaks it down by channel (Stripe, Square, cash, check, …).
 *
 * The order is the immutable receivable (orders.total_gross). "What we sold" is:
 *
 *   net expected = gross − refunds − comps − write-offs + donations
 *
 * Only COMPLETED and AWAITING_OFFLINE_PAYMENT orders are counted (a sale we still
 * expect to collect). Money is attributed to a channel from the ledger row's own
 * payment method (an in-person card reconciles under SQUARE); Stripe receipts are
 * the STRIPE channel. Refunds are an order-level rollup with no method of their
 * own, so each order's refund is attributed to the channel of its largest
 * settling payment. Channel labels are translated on the frontend.
 */
class EventReconciliationService
{
    private const COUNTED_STATUSES = "('COMPLETED', 'AWAITING_OFFLINE_PAYMENT')";

    /**
     * Channel of an order, used to attribute its refund. Stripe orders are the
     * STRIPE channel; everything else takes the channel of its largest settling
     * ledger payment (a CREDIT_CARD receipt reconciles under SQUARE).
     */
    private const ORDER_CHANNEL_SQL = <<<'SQL'
        CASE
            WHEN o.payment_provider = 'STRIPE' THEN 'STRIPE'
            ELSE COALESCE((
                SELECT CASE op2.payment_method
                           WHEN 'CREDIT_CARD' THEN 'SQUARE'
                           WHEN 'CASH' THEN 'CASH'
                           WHEN 'CHECK' THEN 'CHECK'
                           WHEN 'BANK_TRANSFER' THEN 'BANK_TRANSFER'
                           ELSE 'OTHER'
                       END
                FROM order_payments op2
                WHERE op2.order_id = o.id
                  AND op2.transaction_type = 'PAYMENT'
                  AND op2.amount > 0
                  AND op2.deleted_at IS NULL
                ORDER BY op2.amount DESC
                LIMIT 1
            ), 'OTHER')
        END
    SQL;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly EventChannelFeeRepositoryInterface $eventChannelFeeRepository,
    ) {}

    public function reconcile(int $eventId): EventReconciliationResponseDTO
    {
        $event = $this->eventRepository->findById($eventId);
        $currency = $event->getCurrency();

        $statuses = self::COUNTED_STATUSES;
        $channelExpr = self::ORDER_CHANNEL_SQL;

        // An order counts toward reconciliation if it is an active/expected sale,
        // OR it actually moved money — e.g. a CANCELLED order whose Stripe charge
        // was kept (to dodge a double processing fee) or refunded. RESERVED and
        // ABANDONED carts moved no money and stay out. Counting money-bearing
        // orders regardless of status is what lets the breakdown tie to the
        // payment processor's own gross / refund / net figures.
        $inScope = <<<SQL
            (
                o.status IN {$statuses}
                OR o.total_refunded > 0
                OR EXISTS (
                    SELECT 1 FROM stripe_payments sp_scope
                    WHERE sp_scope.order_id = o.id
                      AND sp_scope.amount_received > 0
                      AND sp_scope.deleted_at IS NULL
                )
                OR EXISTS (
                    SELECT 1 FROM order_payments op_scope
                    WHERE op_scope.order_id = o.id
                      AND op_scope.deleted_at IS NULL
                )
            )
            SQL;

        // Gross volume for the event (channel-agnostic top line; money-bearing
        // cancelled orders included, then netted back out by the refunds line).
        $grossTotal = (float) ($this->db->selectOne(<<<SQL
            SELECT COALESCE(SUM(o.total_gross), 0) AS gross
            FROM orders o
            WHERE o.event_id = :eventId
              AND o.deleted_at IS NULL
              AND {$inScope}
        SQL, ['eventId' => $eventId])->gross ?? 0);

        // Refunds grouped by the order's settling channel.
        $refundRows = $this->db->select(<<<SQL
            SELECT {$channelExpr} AS channel,
                   COALESCE(SUM(o.total_refunded), 0) AS amount
            FROM orders o
            WHERE o.event_id = :eventId
              AND o.deleted_at IS NULL
              AND {$inScope}
              AND o.total_refunded > 0
            GROUP BY 1
        SQL, ['eventId' => $eventId]);

        // Ledger receipts grouped by transaction type and method. PAYMENT/DONATION
        // carry a method (the channel); COMP/WRITE_OFF settle without money.
        $ledgerRows = $this->db->select(<<<SQL
            SELECT op.transaction_type AS transaction_type,
                   op.payment_method   AS payment_method,
                   COALESCE(SUM(op.amount), 0) AS amount
            FROM order_payments op
            INNER JOIN orders o ON o.id = op.order_id
            WHERE o.event_id = :eventId
              AND op.deleted_at IS NULL
              AND o.deleted_at IS NULL
              AND {$inScope}
            GROUP BY op.transaction_type, op.payment_method
        SQL, ['eventId' => $eventId]);

        // Confirmed Stripe receipts (amount_received is in minor units).
        $stripeRow = $this->db->selectOne(<<<SQL
            SELECT COALESCE(SUM(sp.amount_received), 0) / 100.0 AS received
            FROM stripe_payments sp
            INNER JOIN orders o ON o.id = sp.order_id
            WHERE o.event_id = :eventId
              AND o.deleted_at IS NULL
              AND {$inScope}
              AND sp.amount_received > 0
        SQL, ['eventId' => $eventId]);
        $stripeReceived = round((float) ($stripeRow->received ?? 0), 2);

        $salesByChannel = [];
        $donationsByChannel = [];
        $comps = 0.0;
        $writeOffs = 0.0;

        foreach ($ledgerRows as $row) {
            $amount = round((float) $row->amount, 2);
            $channel = self::channelForMethod($row->payment_method);

            match ($row->transaction_type) {
                PaymentTransactionType::PAYMENT->value => $salesByChannel[$channel] = round(($salesByChannel[$channel] ?? 0) + $amount, 2),
                PaymentTransactionType::DONATION->value => $donationsByChannel[$channel] = round(($donationsByChannel[$channel] ?? 0) + $amount, 2),
                PaymentTransactionType::COMP->value => $comps += $amount,
                PaymentTransactionType::WRITE_OFF->value => $writeOffs += $amount,
                default => null,
            };
        }

        // Stripe receipts are the STRIPE channel; they are not ledger rows.
        if ($stripeReceived > 0) {
            $salesByChannel[PaymentChannel::STRIPE->value] = round(($salesByChannel[PaymentChannel::STRIPE->value] ?? 0) + $stripeReceived, 2);
        }

        $fees = $this->eventChannelFeeRepository->findWhere([
            EventChannelFeeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        return $this->assemble(
            currency: $currency,
            grossTotal: $grossTotal,
            salesByChannel: $salesByChannel,
            donationsByChannel: $donationsByChannel,
            refundsByChannel: $this->indexByChannel($refundRows, 'amount'),
            comps: round($comps, 2),
            writeOffs: round($writeOffs, 2),
            feesByChannel: $fees->mapWithKeys(static fn (EventChannelFeeDomainObject $f) => [$f->getChannel() => round((float) $f->getFeeAmount(), 2)])->all(),
            feesUpdatedAt: $fees->max(static fn (EventChannelFeeDomainObject $f) => $f->getUpdatedAt()) ?: null,
            feesUpdatedBy: $fees->isNotEmpty() ? $fees->sortByDesc(static fn (EventChannelFeeDomainObject $f) => $f->getUpdatedAt())->first()->getRecordedByUserId() : null,
        );
    }

    /**
     * Pure assembly of the reconciliation totals and per-channel breakdown.
     * Kept free of database access so the money math is unit-testable directly.
     *
     * @param  array<string, float>  $salesByChannel  PaymentChannel value => money received (incl. Stripe)
     * @param  array<string, float>  $donationsByChannel
     * @param  array<string, float>  $refundsByChannel
     * @param  array<string, float>  $feesByChannel
     */
    public function assemble(
        string $currency,
        float $grossTotal,
        array $salesByChannel,
        array $donationsByChannel,
        array $refundsByChannel,
        float $comps,
        float $writeOffs,
        array $feesByChannel,
        ?string $feesUpdatedAt = null,
        ?int $feesUpdatedBy = null,
    ): EventReconciliationResponseDTO {
        $comps = round($comps, 2);
        $writeOffs = round($writeOffs, 2);

        $totalReceived = 0.0;
        foreach (PaymentChannel::displayOrder() as $channel) {
            $key = $channel->value;
            $totalReceived += round((float) ($salesByChannel[$key] ?? 0), 2) + round((float) ($donationsByChannel[$key] ?? 0), 2);
        }
        $totalReceived = round($totalReceived, 2);

        $channels = [];
        $totalFees = 0.0;
        $totalRefunds = 0.0;
        $totalDonations = 0.0;

        foreach (PaymentChannel::displayOrder() as $channel) {
            $key = $channel->value;
            $sales = round((float) ($salesByChannel[$key] ?? 0), 2);
            $donations = round((float) ($donationsByChannel[$key] ?? 0), 2);
            $refunds = round((float) ($refundsByChannel[$key] ?? 0), 2);
            $fee = round((float) ($feesByChannel[$key] ?? 0), 2);

            $totalFees += $fee;
            $totalRefunds += $refunds;
            $totalDonations += $donations;

            // Skip channels with no money and no fee set — keeps the table tidy.
            if ($sales === 0.0 && $donations === 0.0 && $refunds === 0.0 && $fee === 0.0) {
                continue;
            }

            $received = round($sales + $donations, 2);

            $channels[] = new EventReconciliationChannelDTO(
                channel: $key,
                sales: $sales,
                donations: $donations,
                refunds: $refunds,
                fee: $fee,
                net: round($sales + $donations - $refunds - $fee, 2),
                share: $totalReceived > 0 ? round($received / $totalReceived, 4) : 0.0,
            );
        }

        $totalFees = round($totalFees, 2);
        $totalRefunds = round($totalRefunds, 2);
        $totalDonations = round($totalDonations, 2);

        $collected = round($totalReceived - $totalRefunds, 2);

        return new EventReconciliationResponseDTO(
            currency: $currency,
            gross_sales: round($grossTotal, 2),
            refunds: $totalRefunds,
            comps: $comps,
            write_offs: $writeOffs,
            donations: $totalDonations,
            net_expected_funds: round($grossTotal - $totalRefunds - $comps - $writeOffs + $totalDonations, 2),
            total_received: $totalReceived,
            collected: $collected,
            total_fees: $totalFees,
            net_to_bank: round($collected - $totalFees, 2),
            channels: $channels,
            fees_updated_at: $feesUpdatedAt,
            fees_updated_by_user_id: $feesUpdatedBy,
        );
    }

    /**
     * Maps a ledger row's payment method to its reconciliation channel. A null
     * method (a legacy donation, or a comp/write-off) falls to OTHER.
     */
    private static function channelForMethod(?string $method): string
    {
        return match ($method) {
            'CREDIT_CARD' => PaymentChannel::SQUARE->value,
            'CASH' => PaymentChannel::CASH->value,
            'CHECK' => PaymentChannel::CHECK->value,
            'BANK_TRANSFER' => PaymentChannel::BANK_TRANSFER->value,
            default => PaymentChannel::OTHER->value,
        };
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<string, float>
     */
    private function indexByChannel(array $rows, string $field): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row->channel] = round((float) $row->{$field}, 2);
        }

        return $indexed;
    }
}
