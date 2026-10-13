<?php

namespace App\Support\Wallet;

/**
 * Wallet types. Phase 8 introduces only the main wallet; further types
 * (commission, cashback, rewards) are added here when their rules exist.
 */
enum WalletType: string
{
    case Main = 'main';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Main wallet',
        };
    }
}
