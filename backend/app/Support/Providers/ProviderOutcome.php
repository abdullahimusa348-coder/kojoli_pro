<?php

namespace App\Support\Providers;

/**
 * Normalized result of one provider purchase or status query.
 * succeeded / failed_definite only when the provider's documented response
 * says so; everything else (timeout, connection error, 5xx, malformed or
 * undocumented response, ambiguity) is unknown.
 */
enum ProviderOutcome: string
{
    case Succeeded = 'succeeded';
    case FailedDefinite = 'failed_definite';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => 'Succeeded',
            self::FailedDefinite => 'Failed',
            self::Unknown => 'Unknown',
        };
    }

    public function isDefinite(): bool
    {
        return $this !== self::Unknown;
    }
}
