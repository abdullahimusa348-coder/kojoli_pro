<?php

namespace App\Support\Phone;

/**
 * Shared Nigerian phone number input hygiene (Phase 10). Accepts only:
 * - 11-digit local format: 0 followed by 10 digits (e.g. 08012345678);
 * - international format: +234 followed by 10 digits (e.g. +2348012345678).
 * Spaces, dashes, dots and brackets are ignored. The canonical form is the
 * 11-digit local format, so equivalent inputs compare and fingerprint the
 * same. No network-prefix validation (no business rule or verified provider
 * requirement for it).
 */
class NigerianPhone
{
    /** Canonical 11-digit local form, or null when the input is not an accepted format. */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $compact = preg_replace('/[\s\-.()]/', '', $input);

        if (preg_match('/^0\d{10}$/', $compact)) {
            return $compact;
        }
        if (preg_match('/^\+234(\d{10})$/', $compact, $m)) {
            return '0'.$m[1];
        }

        return null;
    }

    public static function isCanonical(?string $value): bool
    {
        return $value !== null && self::normalize($value) === $value;
    }
}
