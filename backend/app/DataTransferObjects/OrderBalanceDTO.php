<?php

namespace HiEvents\DataTransferObjects;

class OrderBalanceDTO extends BaseDataObject
{
    public function __construct(
        public readonly float $amountOwed,
        public readonly float $amountCollected,
        public readonly float $totalComps,
        public readonly float $totalRefunded,
        public readonly float $balance,
        public readonly float $overpaid,
        public readonly bool  $isSettled,
    )
    {
    }
}
