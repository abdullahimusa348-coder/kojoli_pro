<?php

namespace App\Support\Pricing;

/**
 * Converts naira amounts typed by staff ("1,250.50") to integer kobo and back,
 * using string arithmetic only (never floats). Thousands separators must be
 * in the right places; at most two decimal places.
 */
class KoboAmount
{
    private const PATTERN = '/^(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?$/';

    /** Kobo for a naira string, or null when the input is not a valid amount. */
    public static function parse(mixed $input): ?int
    {
        if (is_int($input)) {
            $input = (string) $input;
        }
        if (! is_string($input)) {
            return null;
        }

        $input = trim($input);
        if (! preg_match(self::PATTERN, $input, $matches)) {
            return null;
        }

        $naira = ltrim(str_replace(',', '', strstr($input, '.', true) ?: $input), '0') ?: '0';
        if (strlen($naira) > 15) {
            return null; // far beyond any configurable limit; avoids integer overflow
        }

        $kobo = str_pad($matches[1] ?? '', 2, '0');

        return (int) $naira * 100 + (int) $kobo;
    }

    /** Plain naira string for form values, e.g. 125050 → "1250.50". */
    public static function toInput(?int $kobo): string
    {
        if ($kobo === null) {
            return '';
        }

        return intdiv($kobo, 100).'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }
}
