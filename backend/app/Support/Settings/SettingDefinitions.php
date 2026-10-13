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
        'payments' => 'Payments',
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
                'description' => 'When on, customers cannot start any new purchase anywhere in the purchase system, including Data, Airtime, NIN, BVN and Exam PIN. Purchases already in progress, re-checks, refunds and the admin area keep working; wallet funding is not affected.',
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
            'payments.min_funding_kobo' => [
                'type' => SettingType::Integer,
                'value' => 10_000,
                'label' => 'Minimum wallet funding (kobo)',
                'description' => 'Smallest amount a customer may fund their wallet with, in kobo (10,000 kobo = ₦100).',
                'is_public' => false,
                'rules' => ['min:100', 'max:100000000000000'],
            ],
            'payments.max_funding_kobo' => [
                'type' => SettingType::Integer,
                'value' => 50_000_000,
                'label' => 'Maximum wallet funding (kobo)',
                'description' => 'Largest single wallet funding, in kobo (50,000,000 kobo = ₦500,000). Never above the pricing maximum amount.',
                'is_public' => false,
                'rules' => ['min:100', 'max:100000000000000'],
            ],
            'payments.pending_expiry_minutes' => [
                'type' => SettingType::Integer,
                'value' => 60,
                'label' => 'Pending payment expiry (minutes)',
                'description' => 'After this time, a payment the gateway still reports as unpaid is marked failed by reconciliation. A payment the gateway confirms later goes to review, never lost.',
                'is_public' => false,
                'rules' => ['min:5', 'max:10080'],
            ],
            'payments.live_enabled' => [
                'type' => SettingType::Boolean,
                'value' => false,
                'label' => 'Allow live payments',
                'description' => 'Master switch. While off, no gateway makes live calls, whatever its mode; live gateways show as not configured.',
                'is_public' => false,
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
