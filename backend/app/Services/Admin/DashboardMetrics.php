<?php

namespace App\Services\Admin;

use App\Models\Purchase;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Admin\AdminModule;
use App\Support\BusinessTime;
use App\Support\Money;
use App\Support\Purchases\PurchaseStatus;
use Illuminate\Support\Str;

/**
 * Figures for the admin dashboard home. Only real data is shown: modules that
 * do not exist yet report zero and are flagged `live => false` so the view can
 * say so. Each card is limited to staff holding the matching module permission.
 */
class DashboardMetrics
{
    /**
     * @return list<array{key: string, label: string, value: string, live: bool, note: string, url?: string, link?: string}> cards the staff member may see
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
            [
                'key' => 'wallet-balance',
                'label' => 'Wallet Balance',
                'module' => AdminModule::Wallet,
                'value' => Money::format((int) Wallet::sum('balance_kobo')),
                'live' => true,
                'note' => 'Total held in customer wallets',
            ],
            [
                'key' => 'todays-sales',
                'label' => 'Today’s Sales',
                'module' => AdminModule::Purchases,
                // Computed only for staff who may see the card.
                'resolve' => fn () => $this->todaysSales(),
            ],
            $this->notLive('todays-revenue', 'Today’s Revenue', AdminModule::Reports, Money::format(0)),
            $this->notLive('pending-withdrawals', 'Pending Withdrawals', AdminModule::Withdrawals, '0'),
        ];

        return array_values(array_map(
            fn (array $card) => array_diff_key(isset($card['resolve']) ? [...$card, ...($card['resolve'])()] : $card, ['module' => true, 'resolve' => true]),
            array_filter($cards, fn (array $card) => $staff->can($card['module']->permission())),
        ));
    }

    /**
     * Purchases that became successful during the current business day
     * (BusinessTime, default Africa/Lagos): their count and the total charged.
     * Pending, review and failed purchases are not sales.
     *
     * @return array{count: int, total_kobo: int}
     */
    public function salesToday(): array
    {
        $row = Purchase::query()->where('status', PurchaseStatus::Successful->value)->completedToday()
            ->selectRaw('COUNT(*) AS sales_count, COALESCE(SUM(amount_kobo), 0) AS sales_total')
            ->toBase()->first();

        return ['count' => (int) $row->sales_count, 'total_kobo' => (int) $row->sales_total];
    }

    /** @return array{value: string, live: bool, note: string, url: string, link: string} */
    private function todaysSales(): array
    {
        $sales = $this->salesToday();

        return [
            'value' => Money::format($sales['total_kobo']),
            'live' => true,
            'note' => $sales['count'].' successful '.Str::plural('purchase', $sales['count']).' today ('.BusinessTime::timezone().')',
            'url' => route('admin.purchases', ['status' => PurchaseStatus::Successful->value, 'completed' => 'today']),
            'link' => 'View today’s purchases',
        ];
    }

    /** Recent transactions panel is visible only with the Transactions permission. */
    public function showsRecentTransactions(SystemUser $staff): bool
    {
        return $staff->can(AdminModule::Transactions->permission());
    }

    /**
     * The five latest customer transactions (real data only).
     *
     * @return list<Transaction>
     */
    public function recentTransactions(): array
    {
        return Transaction::with('user:id,name')->latest('id')->limit(5)->get()->all();
    }

    /** @return array{key: string, label: string, module: AdminModule, value: string, live: bool, note: string} */
    private function notLive(string $key, string $label, AdminModule $module, string $zero, ?string $note = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'module' => $module,
            'value' => $zero,
            'live' => false,
            'note' => $note ?? "No data yet: {$module->label()} module arrives in Phase {$module->plannedPhase()}",
        ];
    }
}
