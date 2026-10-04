<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Purchases\RecheckPurchase;
use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseStatusChange;
use App\Models\Service;
use App\Services\Purchases\PurchaseMonitor;
use App\Support\BusinessTime;
use App\Support\Catalog\Network;
use App\Support\Phone\NigerianPhone;
use App\Support\Purchases\PurchaseStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Customer purchases (purchases.view). "Re-check with provider" needs
 * purchases.manage and runs the CP4 re-check (authorization checked again
 * inside the action). There is no mark-successful, force-fail/refund, edit or
 * delete: only a definite provider outcome settles a purchase.
 */
class PurchaseController extends Controller
{
    public function index(Request $request, PurchaseMonitor $monitor): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(PurchaseStatus::class)],
            'service' => ['nullable', 'integer'],
            'network' => ['nullable', Rule::enum(Network::class)],
            'provider' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            // Completed during the current business day: the same window as Today's Sales.
            'completed' => ['nullable', Rule::in(['today'])],
            // Pending or review purchases whose status check is overdue (PurchaseMonitor).
            'overdue' => ['nullable', Rule::in(['1'])],
        ]);

        $purchases = Purchase::query()
            ->select(['id', 'reference', 'user_id', 'service_name', 'product_name', 'plan_name', 'network', 'recipient', 'amount_kobo', 'status',
                'successful_attempt_id', 'next_check_at', 'created_at'])
            // When the purchase moved to review (its first transition into review).
            ->addSelect(['review_since' => PurchaseStatusChange::select('created_at')->whereColumn('purchase_id', 'purchases.id')
                ->where('new_status', PurchaseStatus::Review->value)->whereColumn('old_status', '!=', 'new_status')->orderBy('id')->limit(1)])
            ->withCasts(['review_since' => 'datetime'])
            ->with(['user:id,name,email', 'successfulAttempt:id,provider_id,route_priority', 'successfulAttempt.provider:id,name'])
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $phone = NigerianPhone::normalize($term);
                $query->where(fn ($q) => $q->where('reference', 'like', $like)
                    ->orWhere('recipient', 'like', $phone !== null ? $phone : $like)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)));
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['service'] ?? null, fn ($query, $service) => $query->where('service_id', $service))
            ->when($filters['network'] ?? null, fn ($query, string $network) => $query->where('network', $network))
            ->when($filters['provider'] ?? null, fn ($query, $provider) => $query->whereHas('attempts', fn ($a) => $a->where('provider_id', $provider)))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('created_at', '<=', $to.' 23:59:59'))
            ->when(($filters['completed'] ?? null) === 'today', fn ($query) => $query->completedToday())
            ->when(($filters['overdue'] ?? null) === '1', fn ($query) => $query->checkOverdue())
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.purchases.index', [
            'purchases' => $purchases,
            'monitor' => $monitor->summary(),
            // Rows on this page whose check is overdue, by the same rule as the count.
            'overdueIds' => Purchase::query()->checkOverdue()->whereKey($purchases->pluck('id'))->pluck('id')->all(),
            'filters' => $filters,
            'statuses' => PurchaseStatus::cases(),
            'networks' => Network::cases(),
            'services' => Service::whereIn('id', Purchase::select('service_id')->distinct())->orderBy('name')->get(['id', 'name']),
            'providers' => Provider::orderBy('name')->get(['id', 'name']),
            'businessTimezone' => BusinessTime::timezone(),
        ]);
    }

    public function show(Purchase $purchase): View
    {
        $purchase->load(['user:id,name,email,phone', 'debitTransaction', 'refundTransaction', 'attempts.provider:id,name,code',
            'statusChanges.changedBy']);

        return view('admin.purchases.show', ['purchase' => $purchase]);
    }

    public function recheck(Request $request, Purchase $purchase, RecheckPurchase $recheck): RedirectResponse
    {
        if ($purchase->isFinal()) {
            return back()->withErrors(['recheck' => 'This purchase is already '.strtolower($purchase->status->label()).' and can no longer change.']);
        }

        $before = $purchase->status;
        $after = $recheck->handle($purchase, $request->user('admin'));

        return back()->with('status', $after->status === $before
            ? 'Re-checked: no definite provider outcome yet ('.$after->status->label().').'
            : 'Re-checked: the purchase is now '.$after->status->label().'.');
    }
}
