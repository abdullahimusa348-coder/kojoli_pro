<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Support\Enums\SystemPermission;
use App\Support\Referrals\ReferralCodes;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Referrals tab of the Referral & Commission area (referrals.view): every
 * referral link, newest first, with both customers' name and email, the
 * link date and the referrer's code; search by name, email or code.
 * Read-only: links are made only when a new customer signs up, and staff can
 * never add, change or remove one.
 */
class ReferralLinkController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $links = Referral::query()
            ->join('users as referrer', 'referrer.id', '=', 'referrals.referrer_id')
            ->join('users as referred', 'referred.id', '=', 'referrals.referred_user_id')
            ->leftJoin('referral_codes', 'referral_codes.user_id', '=', 'referrals.referrer_id')
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(function ($q) use ($term, $like) {
                    $q->where('referrer.name', 'like', $like)->orWhere('referrer.email', 'like', $like)
                        ->orWhere('referred.name', 'like', $like)->orWhere('referred.email', 'like', $like)
                        ->orWhere('referral_codes.code', ReferralCodes::normalise($term));
                });
            })
            ->orderByDesc('referrals.id')
            ->paginate(25, ['referrals.id', 'referrals.created_at', 'referrals.referrer_id', 'referrals.referred_user_id',
                'referrer.name as referrer_name', 'referrer.email as referrer_email', 'referral_codes.code as referrer_code',
                'referred.name as referred_name', 'referred.email as referred_email'])
            ->withQueryString();

        return view('admin.referrals.links', [
            'links' => $links,
            'filters' => $filters,
            'canViewCustomers' => $request->user('admin')->can(SystemPermission::CustomersView->value),
        ]);
    }
}
