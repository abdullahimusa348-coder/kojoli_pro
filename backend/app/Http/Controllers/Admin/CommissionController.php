<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Referrals\ActOnCommission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Referrals\CommissionActionRequest;
use App\Models\Commission;
use App\Support\Enums\SystemPermission;
use App\Support\Money;
use App\Support\Referrals\CommissionActionToken;
use App\Support\Referrals\CommissionActionType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * One commission in the Referral & Commission area (referrals.view): its
 * amount, rate and cap, the referrer, the purchase, the wallet credit and its
 * status, derived from its single action, if any (reference, staff member,
 * time, reason and reversal debit). Staff with referrals.manage see the
 * Reverse and Cancel forms while it has no action, each with its own one-time
 * token; ActOnCommission does the rest. The buyer is never shown here, nor
 * anything the purchase delivered.
 */
class CommissionController extends Controller
{
    public function show(Request $request, Commission $commission): Response
    {
        $staff = $request->user('admin');
        $commission->load(['action.actedBy', 'action.reversalTransaction', 'referrer', 'purchase', 'creditTransaction']);
        $open = $commission->action === null && $staff->can(SystemPermission::ReferralsManage->value);

        // The page carries one-time tokens: never stored by the browser or a proxy.
        return response()->view('admin.referrals.commissions.show', [
            'commission' => $commission,
            'tokens' => $open ? [
                'reversal' => CommissionActionToken::issue($commission, CommissionActionType::Reversal, $staff),
                'cancellation' => CommissionActionToken::issue($commission, CommissionActionType::Cancellation, $staff),
            ] : [],
            'canViewCustomers' => $staff->can(SystemPermission::CustomersView->value),
            'canViewPurchases' => $staff->can(SystemPermission::PurchasesView->value),
            'canViewTransactions' => $staff->can(SystemPermission::TransactionsView->value),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function reverse(CommissionActionRequest $request, Commission $commission, ActOnCommission $act): RedirectResponse
    {
        $action = $act->handle($commission, CommissionActionType::Reversal, $request->validated('reason'), $request->validated('token'), $request->user('admin'));

        return redirect()->route('admin.referrals.commissions.show', $commission)->with('status', 'Commission reversed: '.Money::format($commission->amount_kobo)
            .' was debited from the referrer\'s Main Wallet as a separate "Commission reversal" ('.$action->reversalTransaction->reference.').');
    }

    public function cancel(CommissionActionRequest $request, Commission $commission, ActOnCommission $act): RedirectResponse
    {
        $act->handle($commission, CommissionActionType::Cancellation, $request->validated('reason'), $request->validated('token'), $request->user('admin'));

        return redirect()->route('admin.referrals.commissions.show', $commission)->with('status', 'Commission cancelled. No money was moved.');
    }
}
