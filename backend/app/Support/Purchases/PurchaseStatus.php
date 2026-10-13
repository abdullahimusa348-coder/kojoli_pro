<?php

namespace App\Support\Purchases;

/**
 * pending: the wallet was debited and the provider outcome is not known yet
 * (being tried, or unclear and awaiting a provider re-check);
 * successful: a provider confirmed delivery (final);
 * failed: every route tried failed definitely, and the debit was refunded in
 * the same database transaction (final);
 * review: still no definite provider outcome after the re-check window. Staff
 * cannot close it by hand: only a definite provider outcome settles it.
 * An unclear outcome is never refunded or failed over.
 */
enum PurchaseStatus: string
{
    case Pending = 'pending';
    case Successful = 'successful';
    case Failed = 'failed';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Successful => 'Successful',
            self::Failed => 'Failed',
            self::Review => 'Needs review',
        };
    }

    /** Label shown to customers. */
    public function customerLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Successful => 'Successful',
            self::Failed => 'Failed',
            self::Review => 'Under review',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Successful, self::Failed], true);
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Successful, self::Failed, self::Review], true),
            // Only a definite provider outcome (found by a re-check) settles a review purchase.
            self::Review => in_array($next, [self::Successful, self::Failed], true),
            self::Successful, self::Failed => false,
        };
    }
}
