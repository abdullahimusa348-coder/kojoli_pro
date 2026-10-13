<?php

namespace App\Support\Kyc;

use LogicException;

/**
 * A keyed fingerprint of a customer's phone number as a submission saw it (Phase 13 CP2a). It lets a later review detect that
 * the number changed, without a copy of the number in the submission. It is not a verification: a phone is verified only by a
 * real one-time-code flow, which does not exist yet.
 */
final class KycContactFingerprint
{
    private const PHONE_LABEL = 'nadabo:kyc-contact:phone:v1';

    public static function forPhone(#[\SensitiveParameter] string $phone): string
    {
        if ($phone === '') {
            throw new LogicException('A phone fingerprint needs a phone number.');
        }

        return hash_hmac('sha256', $phone, KycHashKeys::derive(self::PHONE_LABEL)[0]);
    }

    /** Whether a stored fingerprint is this phone's, under the current key or any previous one. */
    public static function matches(string $fingerprint, #[\SensitiveParameter] string $phone): bool
    {
        if ($phone === '') {
            return false;
        }
        foreach (KycHashKeys::derive(self::PHONE_LABEL) as $key) {
            if (hash_equals($fingerprint, hash_hmac('sha256', $phone, $key))) {
                return true;
            }
        }

        return false;
    }
}
