<?php

namespace App\Support\Wallet;

/** Customer transaction types. Phase 8: adjustments; Phase 9: wallet funding from verified payments. */
enum TransactionType: string
{
    case Adjustment = 'adjustment';
    case Funding = 'funding';

    public function label(): string
    {
        return match ($this) {
            self::Adjustment => 'Adjustment',
            self::Funding => 'Wallet funding',
        };
    }
}
