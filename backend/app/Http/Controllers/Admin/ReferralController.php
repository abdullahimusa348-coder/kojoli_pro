<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Referral & Commission area, Commissions tab (referrals.view): every
 * commission, newest first, with its status (derived from its action), the
 * amount, the referrer and the purchase, linking to the commission page where
 * staff with referrals.manage reverse or cancel it. Search by commission or
 * purchase reference, or by the referrer's name or email. The buyer is never
 * shown here.
 */
class ReferralController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $commissions = Commission::query()
            ->with(['action:id,commission_id,type', 'referrer:id,name,email', 'purchase:id,reference,service_name'])
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $reference = strtoupper(trim($term));
                $like = '%'.addcslashes(trim($term), '%_\\').'%';
                $query->where(fn ($q) => $q->where('reference', $reference)
                    ->orWhereHas('purchase', fn ($purchase) => $purchase->where('reference', $reference))
                    ->orWhereHas('referrer', fn ($referrer) => $referrer->where('name', 'like', $like)->orWhere('email', 'like', $like)));
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.referrals.index', ['commissions' => $commissions, 'filters' => $filters]);
    }
}
