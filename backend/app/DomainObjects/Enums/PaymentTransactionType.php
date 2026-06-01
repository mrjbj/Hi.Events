<?php

namespace HiEvents\DomainObjects\Enums;

/**
 * What a row on the order payments ledger represents, independent of how the
 * money moved (that is OfflinePaymentMethod). PAYMENT and DONATION are real
 * money collected; COMP and WRITE_OFF settle the balance without money.
 *
 * Refunds are intentionally NOT a ledger type — they live in their own lane
 * (order_refunds + orders.total_refunded) so the ledger holds positive credits
 * only and the balance never double-counts a refund. See OrderBalanceService.
 */
enum PaymentTransactionType: string
{
    use BaseEnum;

    case PAYMENT = 'PAYMENT';
    case DONATION = 'DONATION';
    case COMP = 'COMP';
    case WRITE_OFF = 'WRITE_OFF';

    /**
     * Types that represent money actually collected (counted in "collected")
     * and therefore carry a payment method.
     *
     * @return array<int, self>
     */
    public static function cashReceiptTypes(): array
    {
        return [self::PAYMENT, self::DONATION];
    }

    /**
     * Non-cash credits that settle the balance without money (counted in "comps").
     *
     * @return array<int, self>
     */
    public static function compTypes(): array
    {
        return [self::COMP, self::WRITE_OFF];
    }

    /**
     * Whether a row of this type must record how the money arrived.
     */
    public function requiresPaymentMethod(): bool
    {
        return in_array($this, self::cashReceiptTypes(), true);
    }
}
