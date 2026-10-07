<?php

namespace App\Services\Referrals;

use App\Models\ReferralCode;
use App\Models\User;
use App\Support\Referrals\ReferralCodes;
use App\Support\Referrals\ReferralEligibility;

/**
 * The referrer a signup's referral code names (Phase 12), if the code may be
 * used right now: an existing code whose owner is an Active Subscriber,
 * Vendor or Affiliate. A frozen wallet does not matter. Input is trimmed and
 * upper-cased only. Every other code (malformed, unknown, an API User's or a
 * disabled owner's) is simply not valid, with one message whatever the
 * reason, so the answer never tells which.
 */
final class SignupReferrer
{
    public const INVALID = "This referral code isn't valid";

    /**
     * $sharedLock: read the owner's row with a shared lock, inside the registration transaction, so that disabling
     * the owner or changing their type either waits for this signup to finish or is seen by it.
     */
    public static function find(mixed $input, bool $sharedLock = false): ?User
    {
        if (! is_string($input)) {
            return null;
        }
        $code = ReferralCodes::normalise($input);
        if (! ReferralCodes::isWellFormed($code)) {
            return null;
        }

        $ownerId = ReferralCode::where('code', $code)->value('user_id');
        $owner = $ownerId === null ? null : User::whereKey($ownerId)->when($sharedLock, fn ($query) => $query->sharedLock())->first();

        return $owner !== null && ReferralEligibility::isActiveReferrer($owner) ? $owner : null;
    }
}
