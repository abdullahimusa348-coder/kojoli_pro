<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\Referrals\ReferralCodeIssuer;
use App\Services\Referrals\ReferralSummary;
use App\Services\Wallet\WalletService;
use App\Support\Referrals\ReferralEligibility;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The signed-in customer's Referral page (Phase 12), for Subscribers,
 * Vendors and Affiliates only: an API User gets a 404 (and has no menu
 * item). The first visit creates the customer's Main Wallet, in its own
 * short transaction, and then their permanent referral code. Shows the code,
 * the signup link to share, the referral figures, the anonymous list of
 * referred customers (joined date and Active/Disabled only) and the commission
 * history (credit date, original amount and status only). Nothing here links
 * anyone: referrals are made only when a new customer signs up.
 */
class ReferralController extends Controller
{
    public function __invoke(Request $request, WalletService $wallets, ReferralCodeIssuer $codes, ReferralSummary $summary): View
    {
        $user = $request->user();
        abort_unless(ReferralEligibility::canRefer($user), 404);

        $wallets->walletFor($user); // the wallet commissions will be paid into, before the code
        $code = $codes->codeFor($user)->code;

        return view('user.referrals', [
            'code' => $code,
            'link' => route('register', ['ref' => $code]),
            'figures' => $summary->figures($user),
            'history' => $summary->history($user),
            'commissions' => $summary->commissions($user),
        ]);
    }
}
