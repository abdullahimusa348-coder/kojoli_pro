<?php

namespace App\Services\Admin;

use App\Models\SystemUser;
use App\Models\User;
use App\Support\Admin\AdminModule;
use App\Support\Money;

/**
 * Figures for the admin dashboard home. Only real data is shown: modules that
 * do not exist yet report zero and are flagged `live => false` so the view can
 * say so. Each card is limited to staff holding the matching module permission.
 */
class DashboardMetrics
{
    /**
     * @return list<array{key: string, label: string, value: string, live: bool, note: string}> cards the staff member may see
     */
    public function cardsFor(SystemUser $staff): array
    {
        $cards = [
            [
                'key' => 'total-users',
                'label' => 'Total Users',
                'module' => AdminModule::Users,
                'value' => number_format(User::count()),
                'live' => true,
                'note' => 'Registered customer accounts',
            ],
            $this->notLive('wallet-balance', 'Wallet Balance', AdminModule::Wallet, Money::format(0)),
            $this->notLive('todays-sales', 'Today’s Sales', AdminModule::Transactions, '0'),
            $this->notLive('todays-revenue', 'Today’s Revenue', AdminModule::Reports, Money::format(0)),
            $this->notLive('pending-withdrawals', 'Pending Withdrawals', AdminModule::Withdrawals, '0'),
        ];

        return array_values(array_map(
            fn (array $card) => array_diff_key($card, ['module' => true]),
            array_filter($cards, fn (array $card) => $staff->can($card['module']->permission())),
        ));
    }

    /** Recent transactions panel is visible only with the Transactions permission. */
    public function showsRecentTransactions(SystemUser $staff): bool
    {
        return $staff->can(AdminModule::Transactions->permission());
    }

    /**
     * No transactions table exists until Phase 8.
     *
     * @return list<array<string, mixed>>
     */
    public function recentTransactions(): array
    {
        return [];
    }

    /** @return array{key: string, label: string, module: AdminModule, value: string, live: bool, note: string} */
    private function notLive(string $key, string $label, AdminModule $module, string $zero): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'module' => $module,
            'value' => $zero,
            'live' => false,
            'note' => "No data yet: {$module->label()} module arrives in Phase {$module->plannedPhase()}",
        ];
    }
}
