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

    // Money returned (stored as a negative amount).
    case REFUND = 'REFUND';

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
}
