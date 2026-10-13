<?php

use App\Actions\Admin\Referrals\ActOnCommission;
use App\Actions\Admin\Wallet\ReverseAdjustment;
use App\Exceptions\Wallet\InvalidTransactionState;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Services\Wallet\WalletService;
use App\Support\Referrals\CommissionActionToken;
use App\Support\Referrals\CommissionActionType;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionStatus;
use App\Support\Wallet\TransactionType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Referrals/helpers.php';

/*
 * Phase 12 remediation 1 (F4): a referral commission transaction, its credit and its reversal debit alike, is never
 * reversed, whether the model is saved directly or WalletService is asked (which then writes nothing: its ledger entry
 * and balance roll back with the refusal). A commission changes only through its own one action (CP5). An ordinary manual
 * adjustment is still reversed once, through the adjustment reversal.
 */

beforeEach(function () {
    cmxDrivers();
    Http::preventStrayRequests();
});

/** What a refused reversal must leave as it was: the ledger's entries and reversals, the referrer's balance, the status. */
function ciSnapshot(User $referrer, Transaction $transaction): array
{
    return [WalletLedgerEntry::count(), WalletLedgerEntry::where('entry_type', LedgerEntryType::Reversal->value)->count(),
        cmxBalance($referrer), $transaction->fresh()->status];
}

it('refuses to reverse a commission transaction, its credit or its reversal debit, through the model or the wallet service, changing nothing', function (string $which, string $through) {
    [$referrer, $commission] = cmxCommission(0);
    if ($which === 'reversal debit') {
        $staff = cmxStaff();
        app(ActOnCommission::class)->handle($commission, CommissionActionType::Reversal, 'Purchase disputed by the bank; reviewed by support.',
            CommissionActionToken::issue($commission, CommissionActionType::Reversal, $staff), $staff);
        $transaction = $commission->fresh()->action->reversalTransaction;
    } else {
        $transaction = $commission->creditTransaction;
    }
    $before = ciSnapshot($referrer, $transaction);

    $reverse = function () use ($transaction, $through) {
        if ($through === 'the model') {
            $transaction->status = TransactionStatus::Reversed;
            $transaction->save();

            return;
        }
        app(WalletService::class)->reverse($transaction, 'Taken back after a review of the purchase.', null, 'reverse-'.Str::random(8));
    };

    expect($reverse)->toThrow(InvalidTransactionState::class);
    expect($transaction->fresh()->status)->toBe(TransactionStatus::Successful)
        ->and(ciSnapshot($referrer, $transaction))->toBe($before);
    cmxClean();
})->with([
    'the credit, through the model' => ['credit', 'the model'],
    'the credit, through the wallet service' => ['credit', 'the wallet service'],
    'the reversal debit, through the model' => ['reversal debit', 'the model'],
    'the reversal debit, through the wallet service' => ['reversal debit', 'the wallet service'],
]);

it('still reverses an ordinary manual adjustment, once, through the adjustment reversal', function () {
    $staff = cmxStaff();
    $referrer = cmxReferrer();
    $wallets = app(WalletService::class);
    $credit = $wallets->credit($wallets->walletFor($referrer), 5_000, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment,
        'Test funding', 'adjustment-credit-1', $staff);

    $reversal = app(ReverseAdjustment::class)->handle($credit->transaction, 'Credited the wrong customer by mistake.', 'reverse-adjustment-1', $staff);

    expect($reversal->replayed)->toBeFalse()
        ->and($reversal->entry->entry_type)->toBe(LedgerEntryType::Reversal)
        ->and($credit->transaction->fresh()->status)->toBe(TransactionStatus::Reversed)
        ->and(cmxBalance($referrer))->toBe(0)
        ->and(WalletLedgerEntry::where('entry_type', LedgerEntryType::Reversal->value)->count())->toBe(1);
    cmxClean();
});
