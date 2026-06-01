<?php

namespace HiEvents\DomainObjects\Enums;

/**
 * The money channels a payment can arrive through, used by the event
 * reconciliation widget. STRIPE is online card; SQUARE is an in-person card
 * reader (recorded as an offline CARD ledger entry); the rest mirror the
 * offline payment methods. Channel labels are translated on the frontend.
 */
enum PaymentChannel: string
{
    use BaseEnum;

    case STRIPE = 'STRIPE';
    case SQUARE = 'SQUARE';
    case CASH = 'CASH';
    case CHECK = 'CHECK';
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case OTHER = 'OTHER';

    /**
     * Display order for the reconciliation table.
     *
     * @return array<int, self>
     */
    public static function displayOrder(): array
    {
        return [self::STRIPE, self::SQUARE, self::CASH, self::CHECK, self::BANK_TRANSFER, self::OTHER];
    }
}
