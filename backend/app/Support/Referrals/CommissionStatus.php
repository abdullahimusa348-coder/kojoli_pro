<?php

namespace App\Support\Referrals;

/**
 * What a commission is now (Phase 12). Never stored: a commission is Credited
 * until its single staff action, if any, makes it Reversed or Cancelled
 * (see CommissionActionType). Commission records themselves never change.
 */
enum CommissionStatus: string
{
    case Credited = 'credited';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Credited => 'Credited',
            self::Reversed => 'Reversed',
            self::Cancelled => 'Cancelled',
        };
    }
}
