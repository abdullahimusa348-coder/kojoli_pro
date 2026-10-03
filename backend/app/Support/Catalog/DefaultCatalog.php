<?php

namespace App\Support\Catalog;

/**
 * The starting catalog seeded by ServiceCatalogSeeder: planned Nadabo Global
 * Data services grouped by category. Services are seeded disabled; they are
 * catalog entries only and have no purchasing or business logic yet.
 * NIN and BVN are separate services; Data and Smile Data are separate services.
 * Data durations (daily, weekly, monthly) are future plans, not services.
 */
class DefaultCatalog
{
    /**
     * @return list<array{name: string, icon: CatalogIcon, description: string, services: list<array{name: string, icon: CatalogIcon, description: string}>}>
     */
    public static function categories(): array
    {
        return [
            [
                'name' => 'Telecom',
                'icon' => CatalogIcon::Layers,
                'description' => 'Mobile data, airtime and related telecom services.',
                'services' => [
                    ['name' => 'Data', 'icon' => CatalogIcon::Layers, 'description' => 'Mobile data bundles.'],
                    ['name' => 'Airtime', 'icon' => CatalogIcon::Chat, 'description' => 'Mobile airtime top-up.'],
                    ['name' => 'Airtime to Cash', 'icon' => CatalogIcon::Transfer, 'description' => 'Convert airtime to cash.'],
                    ['name' => 'Alpha Topup', 'icon' => CatalogIcon::Card, 'description' => 'Alpha top-up.'],
                    ['name' => 'Smile Data', 'icon' => CatalogIcon::Server, 'description' => 'Smile network data (separate from Data).'],
                ],
            ],
            [
                'name' => 'Bills & Utilities',
                'icon' => CatalogIcon::Card,
                'description' => 'TV subscriptions, electricity and bill payments.',
                'services' => [
                    ['name' => 'Cable TV', 'icon' => CatalogIcon::Grid, 'description' => 'Cable TV subscriptions.'],
                    ['name' => 'Electricity', 'icon' => CatalogIcon::Bell, 'description' => 'Electricity tokens and bills.'],
                    ['name' => 'Bills Payment', 'icon' => CatalogIcon::Card, 'description' => 'Other bill payments.'],
                ],
            ],
            [
                'name' => 'Education',
                'icon' => CatalogIcon::Chart,
                'description' => 'Examination and education services.',
                'services' => [
                    ['name' => 'Exam Pin', 'icon' => CatalogIcon::Chart, 'description' => 'Examination result-checker PINs.'],
                ],
            ],
            [
                'name' => 'Identity Verification',
                'icon' => CatalogIcon::Shield,
                'description' => 'Identity verification services.',
                'services' => [
                    ['name' => 'NIN', 'icon' => CatalogIcon::Person, 'description' => 'National Identification Number services (separate from BVN).'],
                    ['name' => 'BVN', 'icon' => CatalogIcon::Shield, 'description' => 'Bank Verification Number services (separate from NIN).'],
                ],
            ],
            [
                'name' => 'Wallet & Earnings',
                'icon' => CatalogIcon::Wallet,
                'description' => 'Withdrawals and referral earnings.',
                'services' => [
                    ['name' => 'Withdraw', 'icon' => CatalogIcon::Cash, 'description' => 'Withdraw funds.'],
                    ['name' => 'Referral & Commission', 'icon' => CatalogIcon::People, 'description' => 'Referral earnings and commissions.'],
                ],
            ],
        ];
    }
}
