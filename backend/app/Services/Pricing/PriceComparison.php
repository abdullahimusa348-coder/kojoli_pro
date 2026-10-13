<?php

namespace App\Services\Pricing;

use App\Models\PlanPrice;
use App\Support\Enums\UserType;
use App\Support\Money;
use App\Support\Pricing\BasisPoints;
use Illuminate\Support\Collection;

/**
 * Warnings (never errors) when another customer type is priced higher or
 * lower than Subscriber. Purely informational: prices are never changed or
 * blocked because of them.
 */
class PriceComparison
{
    /**
     * @param  Collection<int, PlanPrice>  $prices
     * @return list<string>
     */
    public static function warnings(Collection $prices): array
    {
        $byType = $prices->keyBy(fn (PlanPrice $p) => $p->user_type->value);
        $base = $byType->get(UserType::Subscriber->value);
        if ($base === null) {
            return [];
        }

        $warnings = [];
        foreach (UserType::cases() as $type) {
            $price = $byType->get($type->value);
            if ($type === UserType::Subscriber || $price === null) {
                continue;
            }

            $warnings = [...$warnings,
                ...self::compare($type, 'price', $price->price_kobo, $base->price_kobo, fn ($v) => Money::format($v)),
                ...self::compare($type, 'discount', $price->discount_bps, $base->discount_bps, fn ($v) => BasisPoints::label($v)),
                ...self::compare($type, 'fee', $price->fee_kobo, $base->fee_kobo, fn ($v) => Money::format($v)),
            ];
        }

        return $warnings;
    }

    /** @return list<string> */
    private static function compare(UserType $type, string $what, ?int $value, ?int $base, callable $format): array
    {
        if ($value === null || $base === null || $value === $base) {
            return [];
        }

        $direction = $value > $base ? 'higher' : 'lower';

        return ["{$type->label()} {$what} {$format($value)} is {$direction} than the Subscriber {$what} {$format($base)}."];
    }
}
