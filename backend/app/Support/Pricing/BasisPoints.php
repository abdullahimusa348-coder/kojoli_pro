<?php

namespace App\Support\Pricing;

/**
 * Converts a percentage typed by staff ("2.5") to basis points (250) and back,
 * without floats. Allowed range 0 to 99.99% (0–9999 bps): a discount can
 * never make the selling price free.
 */
class BasisPoints
{
    public const MAX = 9999;

    public static function parse(mixed $input): ?int
    {
        if (is_int($input)) {
            $input = (string) $input;
        }
        if (! is_string($input) || ! preg_match('/^(\d{1,2})(?:\.(\d{1,2}))?$/', trim($input), $matches)) {
            return null;
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    /** "2.5%"-style label without trailing zeros. */
    public static function label(int $bps): string
    {
        return self::toInput($bps).'%';
    }

    public static function toInput(?int $bps): string
    {
        if ($bps === null) {
            return '';
        }

        $fraction = rtrim(str_pad((string) ($bps % 100), 2, '0', STR_PAD_LEFT), '0');

        return intdiv($bps, 100).($fraction === '' ? '' : '.'.$fraction);
    }
}
