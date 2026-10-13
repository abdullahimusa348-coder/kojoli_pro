<?php

namespace App\Services\Referrals;

use App\Models\ReferralCode;
use App\Models\User;
use App\Support\Referrals\ReferralCodes;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Gives an eligible customer their permanent referral code (Phase 12): the
 * one they already have, or a new random one, created on their first visit
 * to the Referral page. The database's unique keys decide: when the same
 * customer's requests race, every one of them gets the code that was stored;
 * a new code that happens to be another customer's is replaced by another
 * random one, up to RETRIES times. Runs outside any transaction, so each
 * insert stands on its own. The model refuses a code for anyone who is not a
 * Subscriber, Vendor or Affiliate, and a code never changes once stored.
 */
class ReferralCodeIssuer
{
    public const RETRIES = 5;

    public function codeFor(User $user): ReferralCode
    {
        $stored = fn () => ReferralCode::where('user_id', $user->id)->first();
        if ($code = $stored()) {
            return $code;
        }

        for ($try = 0; $try <= self::RETRIES; $try++) {
            try {
                return tap((new ReferralCode)->forceFill(['user_id' => $user->id, 'code' => $this->newCode()]))->save();
            } catch (UniqueConstraintViolationException) {
                // Either this customer's other request stored their code first, or the new code is taken: then try another.
                if ($code = $stored()) {
                    return $code;
                }
            }
        }

        throw new RuntimeException('Could not create a unique referral code. Please try again.');
    }

    /** A new random code; uniqueness is checked by the database when it is stored. */
    protected function newCode(): string
    {
        return ReferralCodes::generate();
    }
}
