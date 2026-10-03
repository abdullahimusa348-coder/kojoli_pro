<?php

namespace App\Support\Pricing;

use App\Services\Settings\SettingsStore;
use App\Support\Settings\SettingDefinitions;

/**
 * System safety limit for prices, fees and face-value limits, from the
 * Settings Store ("pricing.max_amount_kobo", editable by staff with
 * settings.update). Not a business price.
 */
class PricingLimits
{
    public const SETTING = 'pricing.max_amount_kobo';

    public static function maxAmountKobo(): int
    {
        $default = SettingDefinitions::all()[self::SETTING]['value'];

        return (int) app(SettingsStore::class)->get(self::SETTING, $default);
    }
}
