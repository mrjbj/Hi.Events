<?php

namespace HiEvents\Services\Domain\Event;

use HiEvents\DomainObjects\Enums\OrderPaymentType;
use HiEvents\DomainObjects\Enums\PaymentChannel;
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
 * expect to collect). Refunds come from the authoritative orders.total_refunded
 * rollup; they are never ledger rows. Channel labels are translated on the
 * frontend — the backend emits the raw PaymentChannel value.
 */
class EventReconciliationService
{
    private const COUNTED_STATUSES = "('COMPLETED', 'AWAITING_OFFLINE_PAYMENT')";

    /**
     * Maps an order to its channel from the payment provider / offline method.
     * An in-person card (CREDIT_CARD) reconciles under the SQUARE channel.
     */
    private const ORDER_CHANNEL_SQL = <<<'SQL'
        CASE
            WHEN o.payment_provider = 'STRIPE' THEN 'STRIPE'
            WHEN o.offline_payment_method = 'CREDIT_CARD' THEN 'SQUARE'
            WHEN o.offline_payment_method = 'CASH' THEN 'CASH'
            WHEN o.offline_payment_method = 'CHECK' THEN 'CHECK'
            WHEN o.offline_payment_method = 'BANK_TRANSFER' THEN 'BANK_TRANSFER'
            ELSE 'OTHER'
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

        // Gross receivable and refunds, grouped by the order's channel.
        $orderRows = $this->db->select(<<<SQL
            SELECT {$channelExpr} AS channel,
                   COALESCE(SUM(o.total_gross), 0)    AS gross,
                   COALESCE(SUM(o.total_refunded), 0) AS refunds
            FROM orders o
            WHERE o.event_id = :eventId
              AND o.deleted_at IS NULL
              AND o.status IN {$statuses}
            GROUP BY 1
        SQL, ['eventId' => $eventId]);

        // Offline receipts grouped by ledger type (CARD reconciles under SQUARE).
        $ledgerRows = $this->db->select(<<<SQL
            SELECT op.type AS type,
                   COALESCE(SUM(op.amount), 0) AS amount
            FROM order_payments op
            INNER JOIN orders o ON o.id = op.order_id
            WHERE o.event_id = :eventId
              AND op.deleted_at IS NULL
              AND o.deleted_at IS NULL
              AND o.status IN {$statuses}
            GROUP BY op.type
        SQL, ['eventId' => $eventId]);

        // Donations grouped by the order's channel (a DONATION row carries the
        // purpose, not the method, so we attribute it to the order's channel).
        $donationRows = $this->db->select(<<<SQL
            SELECT {$channelExpr} AS channel,
                   COALESCE(SUM(op.amount), 0) AS amount
            FROM order_payments op
            INNER JOIN orders o ON o.id = op.order_id
            WHERE o.event_id = :eventId
              AND op.type = :donationType
              AND op.deleted_at IS NULL
              AND o.deleted_at IS NULL
              AND o.status IN {$statuses}
            GROUP BY 1
        SQL, ['eventId' => $eventId, 'donationType' => OrderPaymentType::DONATION->value]);

        // Confirmed Stripe receipts (amount_received is in minor units).
        $stripeRow = $this->db->selectOne(<<<SQL
            SELECT COALESCE(SUM(sp.amount_received), 0) / 100.0 AS received
            FROM stripe_payments sp
            INNER JOIN orders o ON o.id = sp.order_id
            WHERE o.event_id = :eventId
              AND o.deleted_at IS NULL
              AND o.status IN {$statuses}
              AND sp.amount_received > 0
        SQL, ['eventId' => $eventId]);

        $fees = $this->eventChannelFeeRepository->findWhere([
            EventChannelFeeDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        return $this->assemble(
            currency: $currency,
            grossTotal: array_sum(array_map(static fn ($r) => (float) $r->gross, $orderRows)),
            refundsByChannel: $this->indexByChannel($orderRows, 'refunds'),
            offlineByType: $this->indexLedger($ledgerRows),
            stripeReceived: round((float) ($stripeRow->received ?? 0), 2),
            donationsByChannel: $this->indexByChannel($donationRows, 'amount'),
            feesByChannel: $fees->mapWithKeys(static fn (EventChannelFeeDomainObject $f) => [$f->getChannel() => round((float) $f->getFeeAmount(), 2)])->all(),
            feesUpdatedAt: $fees->max(static fn (EventChannelFeeDomainObject $f) => $f->getUpdatedAt()) ?: null,
            feesUpdatedBy: $fees->isNotEmpty() ? $fees->sortByDesc(static fn (EventChannelFeeDomainObject $f) => $f->getUpdatedAt())->first()->getRecordedByUserId() : null,
        );
    }

    /**
     * Pure assembly of the reconciliation totals and per-channel breakdown.
     * Kept free of database access so the money math is unit-testable directly.
     *
     * @param  array<string, float>  $refundsByChannel
     * @param  array<string, float>  $offlineByType  keyed by OrderPaymentType value
     * @param  array<string, float>  $donationsByChannel
     * @param  array<string, float>  $feesByChannel
     */
    public function assemble(
        string $currency,
        float $grossTotal,
        array $refundsByChannel,
        array $offlineByType,
        float $stripeReceived,
        array $donationsByChannel,
        array $feesByChannel,
        ?string $feesUpdatedAt = null,
        ?int $feesUpdatedBy = null,
    ): EventReconciliationResponseDTO {
        $comps = round((float) ($offlineByType[OrderPaymentType::COMP->value] ?? 0), 2);
        $writeOffs = round((float) ($offlineByType[OrderPaymentType::WRITE_OFF->value] ?? 0), 2);

        $salesByChannel = [
            PaymentChannel::STRIPE->value => round($stripeReceived, 2),
            PaymentChannel::SQUARE->value => round((float) ($offlineByType[OrderPaymentType::CARD->value] ?? 0), 2),
            PaymentChannel::CASH->value => round((float) ($offlineByType[OrderPaymentType::CASH->value] ?? 0), 2),
            PaymentChannel::CHECK->value => round((float) ($offlineByType[OrderPaymentType::CHECK->value] ?? 0), 2),
            PaymentChannel::BANK_TRANSFER->value => round((float) ($offlineByType[OrderPaymentType::BANK_TRANSFER->value] ?? 0), 2),
            PaymentChannel::OTHER->value => round((float) ($offlineByType[OrderPaymentType::OTHER->value] ?? 0), 2),
        ];

        $totalReceived = 0.0;
        foreach (PaymentChannel::displayOrder() as $channel) {
            $key = $channel->value;
            $totalReceived += $salesByChannel[$key] + round((float) ($donationsByChannel[$key] ?? 0), 2);
        }
        $totalReceived = round($totalReceived, 2);

        $channels = [];
        $totalFees = 0.0;
        $totalRefunds = 0.0;
        $totalDonations = 0.0;

        foreach (PaymentChannel::displayOrder() as $channel) {
            $key = $channel->value;
            $sales = $salesByChannel[$key];
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

    /**
     * @param  array<int, object>  $rows
     * @return array<string, float>
     */
    private function indexLedger(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row->type] = round((float) $row->amount, 2);
        }

        return $indexed;
    }
}
