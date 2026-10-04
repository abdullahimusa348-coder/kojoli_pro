<?php

namespace App\Support\Purchases;

use Illuminate\Encryption\Encrypter;
use LogicException;

/**
 * Keyed hashes of a NIN or BVN (Phase 11). There are only 10^11 possible
 * numbers, so a plain hash could be reversed by trying them all; these are
 * HMAC-SHA256 under keys derived in memory from the app key (HKDF-SHA256,
 * one fixed label per purpose). Nothing is added to .env and the keys are
 * never stored or logged.
 * - lookup hash: over "type:number", for exact-match search (the type prefix
 *   keeps NIN and BVN apart);
 * - keyed fingerprint: over plan, type, number and amount, for repeated
 *   requests (the NIN/BVN counterpart of Purchase::fingerprint()).
 * New values use the current app key; comparisons also try every key in
 * APP_PREVIOUS_KEYS, so rotating the app key keeps old purchases findable.
 */
final class IdentityHasher
{
    private const LOOKUP = 'nadabo:purchase-identity:lookup:v1';

    private const FINGERPRINT = 'nadabo:purchase-identity:fingerprint:v1';

    public static function lookupHash(RecipientType $type, #[\SensitiveParameter] string $number): string
    {
        return self::lookupHashes($type, $number)[0];
    }

    /** @return list<string> under the current app key first, then each previous key */
    public static function lookupHashes(RecipientType $type, #[\SensitiveParameter] string $number): array
    {
        return self::hashes(self::LOOKUP, self::assertIdentity($type)->value.':'.$number);
    }

    public static function keyedFingerprint(int $planId, RecipientType $type, #[\SensitiveParameter] string $number, ?int $faceValueKobo): string
    {
        return self::keyedFingerprints($planId, $type, $number, $faceValueKobo)[0];
    }

    /** @return list<string> under the current app key first, then each previous key */
    public static function keyedFingerprints(int $planId, RecipientType $type, #[\SensitiveParameter] string $number, ?int $faceValueKobo): array
    {
        return self::hashes(self::FINGERPRINT, implode('|', [$planId, self::assertIdentity($type)->value, $number, $faceValueKobo ?? '-']));
    }

    /** @return list<string> */
    private static function hashes(string $label, #[\SensitiveParameter] string $message): array
    {
        /** @var Encrypter $encrypter the same parsed keys the encrypted casts use */
        $encrypter = app('encrypter');

        return array_map(fn (string $appKey) => hash_hmac('sha256', $message, hash_hkdf('sha256', $appKey, 32, $label)),
            [$encrypter->getKey(), ...$encrypter->getPreviousKeys()]);
    }

    private static function assertIdentity(RecipientType $type): RecipientType
    {
        if (! $type->isIdentity()) {
            throw new LogicException('Only NIN and BVN numbers are hashed with keys.');
        }

        return $type;
    }
}
