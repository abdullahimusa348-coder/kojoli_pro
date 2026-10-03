<?php

namespace App\Actions\Admin\Wallet;

use App\Models\SystemUser;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use App\Support\Enums\SystemPermission;
use App\Support\Wallet\WalletStatus;

/** Freezes or unfreezes a customer's main wallet (wallet.manage). Frozen: credits allowed, debits blocked. */
class SetWalletStatus
{
    public function __construct(private WalletRules $rules, private WalletService $wallets) {}

    public function handle(User $customer, WalletStatus $status, SystemUser $actor): Wallet
    {
        $this->rules->authorize($actor, SystemPermission::WalletManage);

        return $this->wallets->setStatus($this->wallets->walletFor($customer), $status);
    }
}
