<?php

namespace App\Support\Referrals;

/**
 * The one staff action a commission can ever have (Phase 12). A reversal
 * takes the money back with a separate debit; a cancellation voids the
 * commission without moving money.
 */
enum CommissionActionType: string
{
    case Reversal = 'reversal';
    case Cancellation = 'cancellation';

    public function label(): string
    {
        return match ($this) {
            self::Reversal => 'Reversal',
            self::Cancellation => 'Cancellation',
        };
    }

    /** The commission status this action leads to. */
    public function status(): CommissionStatus
    {
        return match ($this) {
            self::Reversal => CommissionStatus::Reversed,
            self::Cancellation => CommissionStatus::Cancelled,
        };
    }
}
