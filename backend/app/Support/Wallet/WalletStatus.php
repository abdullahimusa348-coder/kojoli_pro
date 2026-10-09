<?php

namespace App\Support\Wallet;

/** Frozen wallets accept credits (so refunds can still arrive) but block debits. */
enum WalletStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Frozen => 'Frozen',
        };
    }
}
