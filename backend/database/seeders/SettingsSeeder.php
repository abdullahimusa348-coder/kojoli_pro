<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\Settings\SettingsStore;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Inserts safe default settings that do not exist yet. Existing rows (and
 * any values changed by staff) are left untouched, so it is safe on every deploy.
 * No credentials are ever seeded.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SettingDefinitions::all() as $key => $definition) {
            $setting = Setting::firstOrNew(['key' => $key]);

            // Metadata follows the code definition; the value is only set on first insert.
            $setting->fill([
                'type' => $definition['type'],
                'group' => Str::before($key, '.'),
                'label' => $definition['label'],
                'description' => $definition['description'],
                'is_public' => $definition['is_public'],
                'is_encrypted' => $definition['is_encrypted'] ?? false,
            ]);

            // Encrypted settings are never seeded with a value; staff enter them in the admin area.
            if (! $setting->exists && ! $setting->is_encrypted) {
                $setting->value = $definition['type']->serialize($definition['value']);
            }

            $setting->save();
        }

        app(SettingsStore::class)->flush();
    }
}
