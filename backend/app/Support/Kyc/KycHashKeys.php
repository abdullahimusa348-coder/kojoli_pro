<?php

namespace App\Support\Kyc;

use Illuminate\Encryption\Encrypter;

/**
 * The keys behind the KYC keyed hashes (Phase 13 CP2a). Each key is HKDF-SHA256 over the app key, with one fixed label per
 * purpose, so a hash is keyed with a secret and never with a value anyone can know. Each previous app key yields its own key
 * too, so hashes made before an app-key rotation still match. The keys are derived in memory: nothing is stored, logged or put
 * in .env.
 */
final class KycHashKeys
{
    /** @return list<string> the key from the current app key first, then one from each previous key */
    public static function derive(string $label): array
    {
        /** @var Encrypter $encrypter the same parsed keys the encrypted casts use */
        $encrypter = app('encrypter');

        return array_map(fn (string $appKey) => hash_hkdf('sha256', $appKey, 32, $label),
            [$encrypter->getKey(), ...$encrypter->getPreviousKeys()]);
    }
}
