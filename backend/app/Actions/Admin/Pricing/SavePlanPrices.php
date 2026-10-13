<?php

namespace App\Actions\Admin\Pricing;

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanPriceChange;
use App\Models\SystemUser;
use App\Support\Enums\UserType;
use App\Support\Pricing\BasisPoints;
use App\Support\Pricing\PricingLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or updates a plan's selling prices for the given customer types in
 * one transaction, writing one append-only history row per actual change.
 * Types not given are left as they are (never removed; disable them instead).
 * New prices start active. No fallback or derived prices are created.
 */
class SavePlanPrices
{
    public function __construct(private PricingRules $rules) {}

    /**
     * @param  array<string, array{price_kobo: ?int, discount_bps: ?int, fee_kobo: ?int}>  $prices  keyed by UserType value
     * @return int number of prices created or changed
     */
    public function handle(Plan $plan, array $prices, SystemUser $actor, ?string $expectedFingerprint = null): int
    {
        $this->rules->authorizeUpdate($actor);

        return DB::transaction(function () use ($plan, $prices, $actor, $expectedFingerprint) {
            $existing = $plan->prices()->lockForUpdate()->get()->keyBy(fn (PlanPrice $p) => $p->user_type->value);

            if ($expectedFingerprint !== null && ! hash_equals(self::fingerprintOf($existing->values()->all()), $expectedFingerprint)) {
                throw ValidationException::withMessages(['prices' => 'These prices were changed by someone else while you were editing. Reload the page and try again.']);
            }

            $changed = 0;
            foreach ($prices as $typeValue => $values) {
                $type = UserType::from($typeValue);
                $values = $this->check($plan, $values);
                $price = $existing->get($type->value);
                $old = $price?->only(['price_kobo', 'discount_bps', 'fee_kobo', 'is_active']);

                if ($price !== null && $old['price_kobo'] === $values['price_kobo'] && $old['discount_bps'] === $values['discount_bps'] && $old['fee_kobo'] === $values['fee_kobo']) {
                    continue;
                }

                $price ??= (new PlanPrice)->forceFill(['plan_id' => $plan->id, 'user_type' => $type, 'is_active' => true]);
                $price->forceFill($values + ['updated_by' => $actor->id])->save();
                self::record($price, $old, $actor);
                $changed++;
            }

            return $changed;
        });
    }

    /** Domain guard (the form request validates input first). @return array{price_kobo: ?int, discount_bps: ?int, fee_kobo: ?int} */
    private function check(Plan $plan, array $values): array
    {
        $max = PricingLimits::maxAmountKobo();
        $price = $values['price_kobo'] ?? null;
        $bps = $values['discount_bps'] ?? null;
        $fee = $values['fee_kobo'] ?? null;

        $valid = $plan->isVariable()
            ? $price === null && $bps !== null && $fee !== null && $bps >= 0 && $bps <= BasisPoints::MAX && $fee >= 0 && $fee <= $max
                && $plan->min_amount_kobo !== null && $plan->max_amount_kobo !== null
            : $price !== null && $bps === null && $fee === null && $price >= 1 && $price <= $max;

        if (! $valid) {
            throw new InvalidArgumentException('Invalid price values for this plan.');
        }

        return ['price_kobo' => $price, 'discount_bps' => $bps, 'fee_kobo' => $fee];
    }

    /** @param  array<string, mixed>|null  $old */
    public static function record(PlanPrice $price, ?array $old, SystemUser $actor): void
    {
        (new PlanPriceChange)->forceFill([
            'plan_price_id' => $price->id,
            'plan_id' => $price->plan_id,
            'user_type' => $price->user_type,
            'old_price_kobo' => $old['price_kobo'] ?? null,
            'new_price_kobo' => $price->price_kobo,
            'old_discount_bps' => $old['discount_bps'] ?? null,
            'new_discount_bps' => $price->discount_bps,
            'old_fee_kobo' => $old['fee_kobo'] ?? null,
            'new_fee_kobo' => $price->fee_kobo,
            'old_is_active' => $old['is_active'] ?? null,
            'new_is_active' => $price->is_active,
            'changed_by' => $actor->id,
        ])->save();
    }

    /** Identifies the current state of a plan's prices, so a stale form cannot overwrite newer edits. */
    public static function fingerprint(Plan $plan): string
    {
        return self::fingerprintOf($plan->prices()->get()->all());
    }

    /** @param  list<PlanPrice>  $prices */
    private static function fingerprintOf(array $prices): string
    {
        $rows = array_map(fn (PlanPrice $p) => [$p->user_type->value, $p->price_kobo, $p->discount_bps, $p->fee_kobo, $p->is_active, $p->updated_at?->getTimestamp()], $prices);
        usort($rows, fn ($a, $b) => strcmp($a[0], $b[0]));

        return sha1(json_encode($rows));
    }
}
