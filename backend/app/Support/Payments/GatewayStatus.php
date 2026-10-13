<?php

namespace App\Support\Payments;

/** Admin-set gateway state. Only active gateways take new payments; maintenance and inactive gateways still verify existing ones. */
enum GatewayStatus: string
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
