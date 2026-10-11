<?php

namespace App\Support\Kyc;

use LogicException;

/**
 * A BVN or NIN as KYC keeps it (Phase 13 CP2a). The plain number is never stored in a searchable column: the encrypted cast
 * keeps it, and beside it only the mask (seven dots and the last four digits) and a keyed lookup hash. The hash is HMAC-SHA256
 * under a KYC-only key (KycHashKeys) and includes the type, so a NIN and a BVN with the same digits never match. Exception
 * messages never contain the number.
 */
final class KycIdentityNumber
{
    private const LABEL = 'nadabo:kyc-identity:lookup:v1';

    /** The canonical 11 digits (whitespace removed), or null when the input is anything else. */
    public static function normalize(#[\SensitiveParameter] ?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $compact = preg_replace('/\s+/', '', $input) ?? '';

        return preg_match('/\A\d{11}\z/', $compact) === 1 ? $compact : null;
    }

    public static function isCanonical(#[\SensitiveParameter] mixed $value): bool
    {
        return is_string($value) && self::normalize($value) === $value;
    }

    /**
     * Whether free text holds an eleven-digit run, such as a BVN, a NIN or a phone number: eleven digits in a row, where neighbouring
     * digits may be separated by one space, no-break space, thin space, hyphen or other dash (so "2222 2222 222" and "2222-2222-222"
     * match). Digits of any script count. This guards against accidental entry; it does not catch other separators or digits spelled out
     * in words.
     */
    public static function containsIdentityNumber(#[\SensitiveParameter] string $text): bool
    {
        return preg_match('/\p{Nd}(?:[ \x{00A0}\x{2009}\x{202F}\x{200B}\x{2010}-\x{2015}\x{2212}-]?\p{Nd}){10}/u', $text) === 1;
    }

    /** The only form that may be displayed: seven dots and the last four digits. */
    public static function mask(#[\SensitiveParameter] string $canonical): string
    {
        self::assertCanonical($canonical);

        return '•••••••'.substr($canonical, -4);
    }

    /** The lookup hash under the current key. */
    public static function lookupHash(KycRequirementType $type, #[\SensitiveParameter] string $canonical): string
    {
        return self::lookupHashes($type, $canonical)[0];
    }

    /** @return list<string> the lookup hash under the current key first, then under each previous key */
    public static function lookupHashes(KycRequirementType $type, #[\SensitiveParameter] string $canonical): array
    {
        self::assertIdentity($type);
        self::assertCanonical($canonical);

        return array_map(fn (string $key) => hash_hmac('sha256', $type->value.':'.$canonical, $key), KycHashKeys::derive(self::LABEL));
    }

    private static function assertIdentity(KycRequirementType $type): void
    {
        if (! in_array($type, [KycRequirementType::Bvn, KycRequirementType::Nin], true)) {
            throw new LogicException('Only a BVN or a NIN has a KYC identity hash.');
        }
    }

    private static function assertCanonical(#[\SensitiveParameter] string $canonical): void
    {
        if (self::normalize($canonical) !== $canonical) {
            throw new LogicException('A KYC identity number is exactly 11 digits.');
        }
    }
}
