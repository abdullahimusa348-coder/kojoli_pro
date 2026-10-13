<?php

namespace App\Support\Kyc;

/**
 * Why an approval cannot be recorded (Phase 13 CP2a): the first rule the submission breaks, and the requirement type the rule concerns
 * when it concerns one. The ledger and the review model turn the same refusal into their own messages.
 */
final readonly class KycApprovalRefusal
{
    public const NO_REQUIREMENTS = 'no_requirements';

    public const STALE_PHONE = 'stale_phone';

    public const UNSUPPORTED = 'unsupported';

    public const IDENTITY_MISSING = 'identity_missing';

    public const IDENTITY_MISMATCH = 'identity_mismatch';

    private function __construct(public string $rule, public ?KycRequirementType $type = null) {}

    public static function noRequirements(): self
    {
        return new self(self::NO_REQUIREMENTS);
    }

    public static function stalePhone(): self
    {
        return new self(self::STALE_PHONE);
    }

    public static function unsupported(KycRequirementType $type): self
    {
        return new self(self::UNSUPPORTED, $type);
    }

    public static function identityMissing(KycRequirementType $type): self
    {
        return new self(self::IDENTITY_MISSING, $type);
    }

    public static function identityMismatch(KycRequirementType $type): self
    {
        return new self(self::IDENTITY_MISMATCH, $type);
    }
}
