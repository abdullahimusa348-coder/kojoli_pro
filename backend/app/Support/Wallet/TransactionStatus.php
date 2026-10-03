<?php

namespace App\Support\Wallet;

/**
 * Smallest correct transaction state model. pending: created, outcome not
 * known yet; successful: completed; failed: did not happen (no money moved,
 * or a refund entry returned it); reversed: succeeded and was then undone by
 * a compensating ledger entry. Allowed: pending → successful|failed,
 * successful → reversed. Everything else is rejected.
 */
enum TransactionStatus: string
{
    case Pending = 'pending';
    case Successful = 'successful';
    case Failed = 'failed';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Successful => 'Successful',
            self::Failed => 'Failed',
            self::Reversed => 'Reversed',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Successful, self::Failed], true),
            self::Successful => $next === self::Reversed,
            self::Failed, self::Reversed => false,
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
