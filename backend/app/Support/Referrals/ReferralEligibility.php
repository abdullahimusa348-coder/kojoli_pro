<?php

namespace App\Support\Referrals;

use App\Models\User;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;

/**
 * Who takes part in referrals right now (Phase 12). Always the customer's
 * current type and status: a change affects only what happens after it, and
 * referral links never change. Staff are system users, never customers, so
 * they can neither refer nor be referred.
 */
final class ReferralEligibility
{
    /** Decision 1: the customer types that can refer and earn. API Users cannot. */
    public const REFERRER_TYPES = [UserType::Subscriber, UserType::Vendor, UserType::Affiliate];

    /** Whether the customer may have a referral page, code and link right now. */
    public static function canRefer(User $user): bool
    {
        return in_array($user->user_type, self::REFERRER_TYPES, true);
    }

    /**
     * Whether the customer's code is usable at signup and they earn new
     * commission right now: an eligible type and an Active account. A frozen
     * wallet stops earning only (checked under the wallet lock when crediting).
     */
    public static function isActiveReferrer(User $user): bool
    {
        return self::canRefer($user) && $user->status === UserStatus::Active;
    }

    /** Decision B2: a buyer who is an API User when the purchase succeeds generates no commission. */
    public static function buyerGeneratesCommission(User $buyer): bool
    {
        return $buyer->user_type !== UserType::ApiUser;
    }
}
