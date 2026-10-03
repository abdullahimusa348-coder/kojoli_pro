<?php

namespace App\Support\Settings;

use App\Support\Enums\SettingType;

/**
 * Settings the application knows about: defaults, labels and extra
 * validation. Seeded by SettingsSeeder (missing keys only, admin edits are
 * never overwritten). Add new safe defaults here; never put credentials here.
 */
class SettingDefinitions
{
    /** Display names for setting groups. */
    public const GROUPS = [
        'app' => 'General',
        'pricing' => 'Pricing',
    ];

    /**
     * @return array<string, array{type: SettingType, value: mixed, label: string, description: string, is_public: bool, rules?: list<string>}>
     */
    public static function all(): array
    {
        return [
            'app.name' => [
                'type' => SettingType::String,
                'value' => 'Nadabo Global Data',
                'label' => 'Platform name',
                'description' => 'Business name shown to customers and staff.',
                'is_public' => true,
                'rules' => ['min:2', 'max:100'],
            ],
            'app.currency' => [
                'type' => SettingType::String,
                'value' => 'NGN',
                'label' => 'Currency code',
                'description' => 'ISO 4217 currency code. Amounts are stored in the minor unit (kobo).',
                'is_public' => true,
                'rules' => ['size:3', 'regex:/^[A-Z]{3}$/'],
            ],
            'app.currency_symbol' => [
                'type' => SettingType::String,
                'value' => '₦',
                'label' => 'Currency symbol',
                'description' => 'Symbol shown before amounts.',
                'is_public' => true,
                'rules' => ['max:5'],
            ],
            'app.timezone' => [
                'type' => SettingType::String,
                'value' => 'Africa/Lagos',
                'label' => 'Business timezone',
                'description' => 'Timezone for business days, e.g. “today’s sales”.',
                'is_public' => true,
                'rules' => ['timezone:all'],
            ],
            'app.maintenance_mode' => [
                'type' => SettingType::Boolean,
                'value' => false,
                'label' => 'Maintenance mode',
                'description' => 'Stored flag for taking customer services offline. Not enforced yet: it takes effect when services are built.',
                'is_public' => true,
            ],
            'pricing.max_amount_kobo' => [
                'type' => SettingType::Integer,
                'value' => 1_000_000_000,
                'label' => 'Maximum amount (kobo)',
                'description' => 'System safety limit for any price, fee or face-value limit, in kobo (100 kobo = ₦1). The default 1,000,000,000 kobo (₦10,000,000) is only a safeguard, not a business price.',
                'is_public' => false,
                'rules' => ['min:100', 'max:100000000000000'],
            ],
        ];
    }

    /** @return list<string> */
    public static function extraRules(string $key): array
    {
        return self::all()[$key]['rules'] ?? [];
    }

    public static function groupLabel(string $group): string
    {
        return self::GROUPS[$group] ?? ucfirst(str_replace(['_', '-'], ' ', $group));
    }
}
