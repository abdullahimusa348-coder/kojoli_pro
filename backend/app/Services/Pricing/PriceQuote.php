<?php

namespace App\Services\Pricing;

use App\Support\Enums\UserType;

/**
 * Result of resolving a plan's selling price for a customer type. All amounts
 * are integer kobo. When unavailable, $reason says why and the amounts are null.
 */
final readonly class PriceQuote
{
    private function __construct(
        public bool $available,
        public UserType $userType,
        public ?string $reason = null,
        public ?int $amountKobo = null,
        public ?int $faceValueKobo = null,
        public ?int $discountKobo = null,
        public ?int $feeKobo = null,
    ) {}

    public static function fixed(UserType $type, int $amountKobo): self
    {
        return new self(true, $type, amountKobo: $amountKobo);
    }

    public static function variable(UserType $type, int $faceValueKobo, int $discountKobo, int $feeKobo): self
    {
        return new self(true, $type, amountKobo: $faceValueKobo - $discountKobo + $feeKobo,
            faceValueKobo: $faceValueKobo, discountKobo: $discountKobo, feeKobo: $feeKobo);
    }

    public static function unavailable(UserType $type, string $reason): self
    {
        return new self(false, $type, reason: $reason);
    }
}
