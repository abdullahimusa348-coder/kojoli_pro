<?php

namespace App\Actions\Admin\Wallet;

use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use Illuminate\Auth\Access\AuthorizationException;

/** Server-side check for the admin wallet actions: active staff holding wallet.view plus every given permission. */
class WalletRules
{
    public function authorize(SystemUser $actor, SystemPermission ...$permissions): void
    {
        foreach ([SystemPermission::WalletView, ...$permissions] as $permission) {
            if (! $actor->isActive() || ! $actor->can($permission->value)) {
                throw new AuthorizationException('You are not allowed to manage customer wallets.');
            }
        }
    }
}
