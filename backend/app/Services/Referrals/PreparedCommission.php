<?php

namespace App\Services\Referrals;

use App\Models\Wallet;
use App\Support\Referrals\CommissionFailureReason;

/**
 * What the commission step decided, under its locks, for a purchase that is
 * about to be marked successful (Phase 12): a commission to credit, with the
 * referrer's locked Main Wallet and the rate and cap in force, or a failure to
 * record once the purchase is successful.
 */
final readonly class PreparedCommission
{
    private function __construct(
        public int $referrerId,
        public ?Wallet $wallet,
        public int $rateBps,
        public int $capKobo,
        public int $amountKobo,
        public ?CommissionFailureReason $failure,
    ) {}

    public static function payable(int $referrerId, Wallet $wallet, int $rateBps, int $capKobo, int $amountKobo): self
    {
        return new self($referrerId, $wallet, $rateBps, $capKobo, $amountKobo, null);
    }

    public static function failed(int $referrerId, CommissionFailureReason $reason): self
    {
        return new self($referrerId, null, 0, 0, 0, $reason);
    }
}
