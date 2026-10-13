<?php

namespace App\Support\Referrals;

/**
 * Decision 2: referral commission applies only to successful purchases of
 * these services, by catalog slug. Smile Data stays blocked, and every other
 * or future service is excluded until its own eligibility and rate are
 * approved.
 */
final class QualifyingServices
{
    public const SLUGS = ['data', 'airtime', 'nin', 'bvn', 'exam-pin'];

    public static function includes(?string $slug): bool
    {
        return in_array($slug, self::SLUGS, true);
    }
}
