<?php

namespace App\Services\Purchases;

use App\Models\Purchase;
use App\Support\Purchases\PurchaseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Read-only figures for staff monitoring of purchases (admin Purchases page
 * and dashboard). "Today" is the business day (Purchase::completedToday());
 * "overdue" uses the reconciliation due rule (Purchase::checkOverdue()).
 * Counts only: no customer data.
 */
class PurchaseMonitor
{
    /**
     * @return array{pending: int, review: int, overdue: int, oldest_pending_at: ?Carbon, successful_today: int, failed_today: int}
     */
    public function summary(): array
    {
        $open = $this->countByStatus(Purchase::query()->whereIn('status', [PurchaseStatus::Pending->value, PurchaseStatus::Review->value]));
        $today = $this->countByStatus(Purchase::query()->completedToday()
            ->whereIn('status', [PurchaseStatus::Successful->value, PurchaseStatus::Failed->value]));
        $oldest = Purchase::query()->where('status', PurchaseStatus::Pending->value)->min('created_at');

        return [
            'pending' => $open[PurchaseStatus::Pending->value] ?? 0,
            'review' => $open[PurchaseStatus::Review->value] ?? 0,
            'overdue' => $this->overdueCount(),
            'oldest_pending_at' => $oldest === null ? null : Carbon::parse($oldest),
            'successful_today' => $today[PurchaseStatus::Successful->value] ?? 0,
            'failed_today' => $today[PurchaseStatus::Failed->value] ?? 0,
        ];
    }

    /** @return array{review: int, overdue: int} what needs staff attention */
    public function attention(): array
    {
        return [
            'review' => Purchase::query()->where('status', PurchaseStatus::Review->value)->count(),
            'overdue' => $this->overdueCount(),
        ];
    }

    public function overdueCount(): int
    {
        return Purchase::query()->checkOverdue()->count();
    }

    /**
     * @param  Builder<Purchase>  $query
     * @return array<string, int> status value => count
     */
    private function countByStatus(Builder $query): array
    {
        return $query->toBase()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)->all();
    }
}
