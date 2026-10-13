<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Wallet\AdjustWallet;
use App\Actions\Admin\Wallet\ReverseAdjustment;
use App\Actions\Admin\Wallet\SetWalletStatus;
use App\Exceptions\Wallet\WalletException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Wallet\ReverseAdjustmentRequest;
use App\Http\Requests\Admin\Wallet\WalletAdjustmentRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use App\Support\Wallet\Direction;
use App\Support\Wallet\WalletStatus;
use App\Support\Wallet\WalletType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Customer wallets (wallet.view). Adjustments and reversals need
 * wallet.adjust, freezing needs wallet.manage. Viewing never creates a
 * wallet: a customer without one simply has ₦0.00 and no history.
 */
class WalletController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'frozen', 'none'])],
        ]);

        $customers = User::query()
            ->leftJoin('wallets', fn ($join) => $join->on('wallets.user_id', '=', 'users.id')->where('wallets.type', WalletType::Main->value))
            ->select('users.*', 'wallets.id as wallet_id', 'wallets.balance_kobo as wallet_balance_kobo', 'wallets.status as wallet_status')
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like)->orWhere('users.phone', 'like', $like));
            })
            ->when(($filters['status'] ?? null) === 'none', fn ($query) => $query->whereNull('wallets.id'))
            ->when(in_array($filters['status'] ?? null, ['active', 'frozen'], true), fn ($query) => $query->where('wallets.status', $filters['status']))
            ->orderBy('users.name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.wallet.index', ['customers' => $customers, 'filters' => $filters]);
    }

    public function show(User $customer): View
    {
        $wallet = $customer->mainWallet();

        return view('admin.wallet.show', [
            'customer' => $customer,
            'wallet' => $wallet,
            'entries' => $wallet?->entries()->with('transaction', 'createdBy')->latest('id')->paginate(25, ['*'], 'entries'),
            'transactions' => $customer->transactions()->with('createdBy', 'entries')->latest('id')->paginate(15, ['*'], 'transactions'),
            'token' => (string) Str::uuid(),
        ]);
    }

    public function adjust(WalletAdjustmentRequest $request, User $customer, AdjustWallet $adjust): RedirectResponse
    {
        try {
            $result = $adjust->handle($customer, Direction::from($request->validated('direction')), $request->amountKobo(),
                $request->validated('reason'), $request->validated('idempotency_key'), $request->user('admin'));
        } catch (WalletException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        $tx = $result->transaction;

        return redirect()->route('admin.wallet.show', $customer)->with('status', $result->replayed
            ? "This adjustment was already recorded ({$tx->reference}); nothing was posted again."
            : "{$tx->direction->label()} of ".Money::format($tx->amount_kobo)." recorded ({$tx->reference}).");
    }

    public function reverse(ReverseAdjustmentRequest $request, User $customer, Transaction $transaction, ReverseAdjustment $reverse): RedirectResponse
    {
        abort_unless($transaction->user_id === $customer->id, 404);

        try {
            $result = $reverse->handle($transaction, $request->validated('reversal_reason'), $request->validated('idempotency_key'), $request->user('admin'));
        } catch (WalletException $e) {
            return back()->withErrors(['reversal' => $e->getMessage()]);
        }

        return redirect()->route('admin.wallet.show', $customer)->with('status', $result->replayed
            ? "{$transaction->reference} was already reversed; nothing was posted again."
            : "{$transaction->reference} reversed ({$result->entry->reference}).");
    }

    public function updateStatus(Request $request, User $customer, SetWalletStatus $set): RedirectResponse
    {
        $status = WalletStatus::from($request->validate(['status' => ['required', Rule::enum(WalletStatus::class)]])['status']);
        $set->handle($customer, $status, $request->user('admin'));

        return back()->with('status', $status === WalletStatus::Frozen
            ? 'Wallet frozen. Debits are blocked; credits are still allowed.'
            : 'Wallet unfrozen.');
    }
}
