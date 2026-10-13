<?php

namespace App\Services\Pricing;

use App\Models\Plan;
use App\Models\User;
use App\Support\Enums\UserType;
use App\Support\Money;
use App\Support\Pricing\PricingLimits;

/**
 * Resolves the selling price of a plan for a customer type. Generic: it looks
 * only at the plan's amount type, its availability chain and its price row,
 * never at which service or network the plan belongs to. Integer kobo only,
 * no automatic fallback between customer types, no side effects.
 */
class PriceResolver
{
    public function quote(Plan $plan, UserType $type, ?int $faceValueKobo = null): PriceQuote
    {
        $plan->loadMissing('product.service.category', 'prices');

        if (! $plan->isAvailable()) {
            return PriceQuote::unavailable($type, 'Plan unavailable: '.$plan->statusLabel().'.');
        }

        $price = $plan->prices->first(fn ($p) => $p->user_type === $type);
        if ($price === null) {
            return PriceQuote::unavailable($type, "No price for {$type->label()}.");
        }
        if (! $price->is_active) {
            return PriceQuote::unavailable($type, "Price disabled for {$type->label()}.");
        }

        $max = PricingLimits::maxAmountKobo();

        if (! $plan->isVariable()) {
            if ($price->price_kobo === null || $price->price_kobo < 1) {
                return PriceQuote::unavailable($type, "No price for {$type->label()}.");
            }
            if ($price->price_kobo > $max) {
                return PriceQuote::unavailable($type, 'Price is above the system maximum amount.');
            }

            return PriceQuote::fixed($type, $price->price_kobo);
        }

        if ($plan->min_amount_kobo === null || $plan->max_amount_kobo === null) {
            return PriceQuote::unavailable($type, 'Amount limits are not set for this plan.');
        }
        if ($faceValueKobo === null) {
            return PriceQuote::unavailable($type, 'Enter an amount.');
        }
        if ($faceValueKobo < $plan->min_amount_kobo || $faceValueKobo > $plan->max_amount_kobo || $faceValueKobo > $max) {
            return PriceQuote::unavailable($type, 'Amount must be between '.$plan->amountLimitsLabel().'.');
        }

        // face ≤ max (≤ 10^14) and bps < 10^4, so the product stays far below PHP_INT_MAX.
        $discount = intdiv($faceValueKobo * ($price->discount_bps ?? 0), 10_000);
        $fee = $price->fee_kobo ?? 0;
        $amount = $faceValueKobo - $discount + $fee;

        if ($amount < 1 || $amount > $max) {
            return PriceQuote::unavailable($type, 'Resulting price is outside the allowed range (up to '.Money::format($max).').');
        }

        return PriceQuote::variable($type, $faceValueKobo, $discount, $fee);
    }

    /** The price for a signed-in customer (web or API token): their own customer type, never another's. */
    public function quoteFor(Plan $plan, User $customer, ?int $faceValueKobo = null): PriceQuote
    {
        if (! $customer->isActive()) {
            return PriceQuote::unavailable($customer->user_type, 'Customer account is disabled.');
        }

        return $this->quote($plan, $customer->user_type, $faceValueKobo);
    }
}
