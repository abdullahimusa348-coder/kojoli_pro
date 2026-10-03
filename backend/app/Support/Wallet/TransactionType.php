<?php

namespace App\Support\Wallet;

/** Customer transaction types. Phase 8: adjustments only. */
enum TransactionType: string
{
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Adjustment => 'Adjustment',
        };
    }
}
