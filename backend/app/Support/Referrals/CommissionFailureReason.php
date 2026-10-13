<?php

namespace App\Support\Referrals;

/**
 * The fixed reason codes of a Failed Commission Attempt (Phase 12): a payable
 * commission that could not be credited. Never used for an eligibility
 * exclusion (no record is made then) or for a database-level abort (the
 * whole purchase success is retried instead).
 */
enum CommissionFailureReason: string
{
    case WalletBalanceLimit = 'wallet_balance_limit';
    case WalletUnavailable = 'wallet_unavailable';
    case WalletRefused = 'wallet_refused';
    case UnexpectedError = 'unexpected_error';

    public function label(): string
    {
        return match ($this) {
            self::WalletBalanceLimit => 'The referrer’s wallet would pass the balance limit',
            self::WalletUnavailable => 'The referrer has no Main Wallet',
            self::WalletRefused => 'The wallet refused the credit',
            self::UnexpectedError => 'Unexpected error',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
