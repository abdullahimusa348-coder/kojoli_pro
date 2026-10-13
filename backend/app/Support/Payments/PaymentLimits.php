<?php

namespace App\Support\Payments;

use App\Services\Settings\SettingsStore;
use App\Support\Pricing\PricingLimits;
use App\Support\Settings\SettingDefinitions;

/**
 * Funding limits and expiry from the Settings Store. The maximum is always
 * capped by the pricing.max_amount_kobo safety limit.
 */
class PaymentLimits
{
    public static function minFundingKobo(): int
    {
        return self::int('payments.min_funding_kobo');
    }

    public static function maxFundingKobo(): int
    {
        return min(self::int('payments.max_funding_kobo'), PricingLimits::maxAmountKobo());
    }

    public static function pendingExpiryMinutes(): int
    {
        return self::int('payments.pending_expiry_minutes');
    }

    public static function liveEnabled(): bool
    {
        return (bool) app(SettingsStore::class)->get('payments.live_enabled', false);
    }

    private static function int(string $key): int
    {
        return (int) app(SettingsStore::class)->get($key, SettingDefinitions::all()[$key]['value']);
    }
}
