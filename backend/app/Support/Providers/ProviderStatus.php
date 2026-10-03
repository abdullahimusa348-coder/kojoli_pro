<?php

namespace App\Support\Providers;

/**
 * Admin-set provider state. Maintenance is a temporary pause (routes are
 * skipped, the reason stays visible); inactive switches the provider off.
 * No automatic "unavailable" state: health checks belong to execution (Phase 10).
 */
enum ProviderStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Maintenance => 'Maintenance',
            self::Inactive => 'Inactive',
        };
    }
}
