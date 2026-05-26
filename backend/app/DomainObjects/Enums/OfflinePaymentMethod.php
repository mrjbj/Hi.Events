<?php

namespace HiEvents\DomainObjects\Enums;

enum OfflinePaymentMethod: string
{
    use BaseEnum;

    case CASH = 'CASH';
    case CHECK = 'CHECK';
    case CREDIT_CARD = 'CREDIT_CARD';
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case OTHER = 'OTHER';
}
