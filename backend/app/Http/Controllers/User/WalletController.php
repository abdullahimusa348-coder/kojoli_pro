<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\Payments\GatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The signed-in customer's own wallet: real balance (₦0.00 until the first
 * entry), ledger history and transactions. Funding is offered only while
 * a configured gateway is active (see FundWalletController); there are no
 * withdrawals, purchases or transfers. Viewing never creates a wallet, and
 * only the customer's own records are queried. Purchase debits and refunds
 * link to their purchase (reference from the transaction's metadata; history
 * entries load their transactions in one query per page).
 */
class WalletController extends Controller
{
    public function __invoke(Request $request, GatewayRegistry $gateways): View
    {
        $user = $request->user();
        $wallet = $user->mainWallet();

        return view('user.wallet', [
            'wallet' => $wallet,
            'balanceKobo' => $wallet?->balance_kobo ?? 0,
            'canFund' => $gateways->usableForFunding()->isNotEmpty(),
            'entries' => $wallet?->entries()->with('transaction:id,type,metadata')->latest('id')
                ->paginate(15, ['id', 'transaction_id', 'reference', 'direction', 'amount_kobo', 'balance_after_kobo', 'entry_type', 'description', 'created_at'], 'entries'),
            'transactions' => $user->transactions()->latest('id')->paginate(10, ['id', 'reference', 'type', 'direction', 'amount_kobo', 'status', 'description', 'metadata', 'created_at'], 'transactions'),
        ]);
    }
}
