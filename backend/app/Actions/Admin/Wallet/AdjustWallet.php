<?php

namespace App\Actions\Admin\Wallet;

use App\Exceptions\Wallet\InvalidAmount;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Wallet\WalletResult;
use App\Services\Wallet\WalletService;
use App\Support\Enums\SystemPermission;
use App\Support\Pricing\PricingLimits;
use App\Support\Wallet\Direction;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;

/**
 * Manual credit or debit of a customer's main wallet (wallet.adjust). Single
 * admin workflow: the reason is kept as an internal note (never shown to the
 * customer), the one-time form token is the idempotency key, and the result
 * is a successful adjustment transaction with one ledger entry. The amount
 * may not exceed the pricing.max_amount_kobo safety limit.
 */
class AdjustWallet
{
    public function __construct(private WalletRules $rules, private WalletService $wallets) {}

    public function handle(User $customer, Direction $direction, int $amountKobo, string $reason, string $idempotencyKey, SystemUser $actor): WalletResult
    {
        $this->rules->authorize($actor, SystemPermission::WalletAdjust);
        if ($amountKobo < 1 || $amountKobo > PricingLimits::maxAmountKobo()) {
            throw new InvalidAmount;
        }

        $wallet = $this->wallets->walletFor($customer);
        $metadata = ['reason' => $reason];

        return $direction === Direction::Credit
            ? $this->wallets->credit($wallet, $amountKobo, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Balance adjustment (credit)', $idempotencyKey, $actor, $metadata)
            : $this->wallets->debit($wallet, $amountKobo, LedgerEntryType::AdjustmentDebit, TransactionType::Adjustment, 'Balance adjustment (debit)', $idempotencyKey, $actor, $metadata);
    }
}
