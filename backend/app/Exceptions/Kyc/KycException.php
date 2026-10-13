<?php

namespace App\Exceptions\Kyc;

use App\Support\Kyc\KycRequirementType;
use RuntimeException;

/**
 * A KYC submission or decision that the rules refuse (Phase 13 CP2a). Each refusal names only what the customer or staff member
 * needs to act on. No message contains an identity number, a document or a file path.
 */
class KycException extends RuntimeException
{
    public static function notRequired(): self
    {
        return new self('No verification is required for your account.');
    }

    public static function alreadyOpen(): self
    {
        return new self('A verification submission is already waiting for review.');
    }

    public static function alreadyApproved(): self
    {
        return new self('Your verification is already approved.');
    }

    public static function correctionNotAllowed(): self
    {
        return new self('A correction can be sent only after your verification is approved, and only for a BVN or NIN that is currently requested.');
    }

    public static function customerInactive(): self
    {
        return new self('This account is not active, so it cannot send a verification submission.');
    }

    public static function phoneMissing(): self
    {
        return new self('Your account needs a phone number before you can send a verification submission.');
    }

    public static function invalidIdentity(KycRequirementType $type): self
    {
        return new self("Enter a valid 11-digit {$type->label()}.");
    }

    public static function identityMissing(KycRequirementType $type): self
    {
        return new self("Enter your {$type->label()}.");
    }

    public static function identityNotRequested(KycRequirementType $type): self
    {
        return new self("{$type->label()} is not requested for this submission.");
    }

    public static function formNotCurrent(): self
    {
        return new self('This form was already used for another request. Reload the page and try again.');
    }

    public static function alreadyDecided(): self
    {
        return new self('This submission has already been decided. Reload the page to see its status.');
    }

    public static function stale(): self
    {
        return new self('This submission changed while you were reviewing it. Reload the page and try again.');
    }

    public static function busy(): self
    {
        return new self('Your change could not be saved because another change to this submission was in progress. Reload the page and try again.');
    }

    public static function reviewerNotAllowed(): self
    {
        return new self('This staff account cannot decide KYC submissions.');
    }

    public static function selfReview(): self
    {
        return new self('This staff account cannot decide this submission.');
    }

    public static function reasonRequired(): self
    {
        return new self('Enter a reason of 10 to 500 characters that includes at least one letter.');
    }

    /** An approval reason is optional and up to 500 characters. The message never repeats what was sent. */
    public static function approvalReasonTooLong(): self
    {
        return new self('An approval reason is up to 500 characters.');
    }

    /**
     * A reason is shown to the customer and kept permanently, so it may not hold an eleven-digit number such as a BVN, NIN or phone number.
     * The message never repeats the input.
     */
    public static function reasonHasIdentityNumber(): self
    {
        return new self('A reason cannot contain an 11-digit number, such as a BVN, NIN or phone number. Remove the number and try again.');
    }

    public static function notApprovable(KycRequirementType $type): self
    {
        return new self("{$type->label()} cannot be completed in this version, so this submission cannot be approved yet.");
    }

    /** A submission with no requirement has nothing an approval could back. */
    public static function noRequirementToApprove(): self
    {
        return new self('This submission has no requirement to approve, so it cannot be approved.');
    }

    public static function identityRecordMissing(KycRequirementType $type): self
    {
        return new self("The {$type->label()} on this submission is missing, so it cannot be approved.");
    }

    public static function identityRecordMismatch(KycRequirementType $type): self
    {
        return new self("The {$type->label()} on this submission does not match its requirement, so it cannot be approved.");
    }
}
