<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FailedCommissionAttempt;
use App\Support\BusinessTime;
use App\Support\Enums\SystemPermission;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Failed Commission Attempts tab of the Referral & Commission area (referrals.view): every recorded failure, newest first,
 * with exactly the four approved fields: the purchase reference, the referrer's internal customer ID, the reason code and
 * the time. No amount, no name, no email and nothing a purchase delivered. Search by purchase reference. Read-only: the
 * list is append-only, and nothing here retries, changes or removes an attempt.
 */
class FailedCommissionAttemptController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $attempts = FailedCommissionAttempt::query()
            ->with('purchase:id,reference')
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->whereHas('purchase',
                fn ($purchase) => $purchase->where('reference', strtoupper(trim($term)))))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.referrals.failed', [
            'attempts' => $attempts,
            'filters' => $filters,
            'zone' => BusinessTime::timezone(),
            'canViewPurchases' => $request->user('admin')->can(SystemPermission::PurchasesView->value),
        ]);
    }
}
