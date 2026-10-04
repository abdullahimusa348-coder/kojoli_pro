<?php

namespace App\Support;

use App\Services\Settings\SettingsStore;
use App\Support\Settings\SettingDefinitions;
use Carbon\CarbonImmutable;

/**
 * The business day in the "Business timezone" setting (default
 * Africa/Lagos). Used only for "today" figures: Today's Sales on the admin
 * dashboard and the matching filter on the admin Purchases list. Every other
 * date in the app is stored and shown as before. Timestamps are stored in the
 * app timezone, so the window is converted to it before querying.
 */
final class BusinessTime
{
    public const SETTING = 'app.timezone';

    /** The configured business timezone; the default when the stored value is not a valid timezone. */
    public static function timezone(): string
    {
        $default = SettingDefinitions::all()[self::SETTING]['value'];
        $zone = app(SettingsStore::class)->get(self::SETTING, $default);

        return is_string($zone) && in_array($zone, timezone_identifiers_list(), true) ? $zone : $default;
    }

    /**
     * The current business day as [start, end): midnight to the next midnight
     * in the business timezone, expressed in the app timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function today(): array
    {
        $start = CarbonImmutable::now(self::timezone())->startOfDay();

        return [$start->setTimezone(config('app.timezone')), $start->addDay()->setTimezone(config('app.timezone'))];
    }
}
