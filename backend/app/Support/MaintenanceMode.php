<?php

namespace App\Support;

use App\Services\Settings\SettingsStore;
use App\Support\Settings\SettingDefinitions;

/**
 * Customer maintenance mode, from the Settings Store ("app.maintenance_mode",
 * Settings -> General, editable by staff with settings.update). While it is
 * on, customers cannot start new Data or Airtime purchases: PurchaseService
 * refuses them and the Buy pages show MESSAGE instead of the forms.
 * Purchases already in progress, re-checks, refunds, the admin area and
 * wallet funding are not affected. Takes effect from the next request.
 */
class MaintenanceMode
{
    public const SETTING = 'app.maintenance_mode';

    public const MESSAGE = 'Buying is temporarily unavailable while we carry out maintenance. Please try again later. Purchases already in progress will still complete.';

    public static function active(): bool
    {
        $default = SettingDefinitions::all()[self::SETTING]['value'];

        return (bool) app(SettingsStore::class)->get(self::SETTING, $default);
    }
}
