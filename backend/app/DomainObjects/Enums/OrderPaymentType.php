<?php

namespace HiEvents\DomainObjects\Enums;

enum OrderPaymentType: string
{
    use BaseEnum;

    // Cash-like receipts — money actually collected.
    case CASH = 'CASH';
    case CHECK = 'CHECK';
    case CARD = 'CARD';
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case OTHER = 'OTHER';

    // Cash received in excess of what was owed.
    case DONATION = 'DONATION';

    // Non-cash credits — settle the balance without money changing hands.
    case COMP = 'COMP';
    case WRITE_OFF = 'WRITE_OFF';

    /**
     * Note: refunds are intentionally NOT a ledger type. They live in their own
     * lane (order_refunds + orders.total_refunded) for both Stripe and offline
     * channels, so the ledger holds positive credits only and the balance never
     * double-counts a refund. See OrderBalanceService.
     */

    /**
     * Types that represent cash actually collected (counted in "collected").
     *
     * @return array<int, self>
     */
    public static function cashReceiptTypes(): array
    {
        return [self::CASH, self::CHECK, self::CARD, self::BANK_TRANSFER, self::OTHER, self::DONATION];
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

    public static function fromOfflinePaymentMethod(OfflinePaymentMethod $method): self
    {
        return match ($method) {
            OfflinePaymentMethod::CASH => self::CASH,
            OfflinePaymentMethod::CHECK => self::CHECK,
            OfflinePaymentMethod::CREDIT_CARD => self::CARD,
            OfflinePaymentMethod::BANK_TRANSFER => self::BANK_TRANSFER,
            OfflinePaymentMethod::OTHER => self::OTHER,
        };
    }
}
