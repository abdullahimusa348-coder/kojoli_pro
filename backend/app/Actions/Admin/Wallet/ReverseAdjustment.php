<?php

namespace App\Actions\Admin\Wallet;

use App\Exceptions\Wallet\InvalidTransactionState;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Services\Wallet\WalletResult;
use App\Services\Wallet\WalletService;
use App\Support\Enums\SystemPermission;
use App\Support\Wallet\TransactionType;

/** Reverses a manual adjustment with a compensating ledger entry (wallet.adjust). Once only. */
class ReverseAdjustment
{
    public function __construct(private WalletRules $rules, private WalletService $wallets) {}

    public function handle(Transaction $transaction, string $reason, string $idempotencyKey, SystemUser $actor): WalletResult
    {
        $this->rules->authorize($actor, SystemPermission::WalletAdjust);
        if ($transaction->type !== TransactionType::Adjustment) {
            throw new InvalidTransactionState('Only manual adjustments can be reversed here.');
        }

        return $this->wallets->reverse($transaction, $reason, $actor, $idempotencyKey);
    }
}
