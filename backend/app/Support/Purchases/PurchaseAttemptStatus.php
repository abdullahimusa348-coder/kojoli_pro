<?php

namespace App\Support\Purchases;

/**
 * started: the call to the provider is being made (an attempt left in this
 * state, e.g. after a crash, is treated as unknown);
 * succeeded: the provider confirmed delivery;
 * failed_definite: the provider definitely did not deliver (documented failure);
 * unknown: timeout, network error or any response that is not a documented
 * definite outcome. Only a provider re-check may settle an unknown attempt.
 */
enum PurchaseAttemptStatus: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case FailedDefinite = 'failed_definite';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Started => 'Started',
            self::Succeeded => 'Succeeded',
            self::FailedDefinite => 'Failed',
            self::Unknown => 'Unknown',
        };
    }

    public function isDefinite(): bool
    {
        return in_array($this, [self::Succeeded, self::FailedDefinite], true);
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Started => in_array($next, [self::Succeeded, self::FailedDefinite, self::Unknown], true),
            self::Unknown => in_array($next, [self::Succeeded, self::FailedDefinite], true),
            self::Succeeded, self::FailedDefinite => false,
        };
    }
}
