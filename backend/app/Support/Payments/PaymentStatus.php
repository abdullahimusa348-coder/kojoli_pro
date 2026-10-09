<?php

namespace App\Support\Payments;

/**
 * pending: created or sent to the gateway, outcome not known yet;
 * successful: verified paid with the exact amount AND credited to the wallet
 * (same database transaction); failed: final, no credit (reason recorded);
 * review: the gateway confirms payment but it could not be credited
 * automatically (amount/currency mismatch, credit refused, or paid after the
 * payment had failed) — staff decide. Never credited without verification.
 */
enum PaymentStatus: string
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

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Successful, self::Failed, self::Review], true),
            self::Review => in_array($next, [self::Successful, self::Failed], true),
            // A verified payment that arrives after the payment failed (e.g. expired) goes to review, never straight to credit.
            self::Failed => $next === self::Review,
            self::Successful => false,
        };
    }
}
