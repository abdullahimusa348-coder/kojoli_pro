<?php

namespace App\Support\Enums;

/**
 * Customer account tier. Drives per-tier pricing in later phases.
 * Add a case here (plus its label) to introduce a new tier.
 */
enum UserType: string
{
    case Subscriber = 'subscriber';
    case Vendor = 'vendor';
    case Affiliate = 'affiliate';
    case ApiUser = 'api_user';

    public function label(): string
    {
        return match ($this) {
            self::Subscriber => 'Subscriber',
            self::Vendor => 'Vendor',
            self::Affiliate => 'Affiliate',
            self::ApiUser => 'API User',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
