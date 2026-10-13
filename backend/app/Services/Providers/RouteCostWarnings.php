<?php

namespace App\Services\Providers;

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanProviderRoute;
use App\Support\Money;
use App\Support\Pricing\BasisPoints;
use Illuminate\Support\Collection;

/**
 * Informational warnings for route costs: cost not set, or cost above an
 * active selling price of any customer type. They never block saving and
 * never affect route eligibility. Provider cost and selling prices stay in
 * their own tables; this only compares them.
 */
class RouteCostWarnings
{
    /**
     * @param  Collection<int, PlanProviderRoute>  $routes
     * @return list<string>
     */
    public static function for(Plan $plan, Collection $routes): array
    {
        $prices = $plan->relationLoaded('activePrices') ? $plan->activePrices : $plan->activePrices()->get();
        $warnings = [];

        foreach ($routes as $route) {
            $name = $route->provider->name;
            if (! $route->hasCost()) {
                $warnings[] = "{$name}: cost not set.";

                continue;
            }

            foreach ($prices as $price) {
                /** @var PlanPrice $price */
                $type = $price->user_type->label();
                if (! $plan->isVariable() && $price->price_kobo !== null && $route->cost_kobo > $price->price_kobo) {
                    $warnings[] = "{$name}: cost ".Money::format($route->cost_kobo)." is higher than the {$type} selling price ".Money::format($price->price_kobo).'.';
                }
                if ($plan->isVariable() && $price->discount_bps !== null && $route->cost_discount_bps < $price->discount_bps) {
                    $warnings[] = "{$name}: provider discount ".BasisPoints::label($route->cost_discount_bps)." is lower than the {$type} selling discount "
                        .BasisPoints::label($price->discount_bps).' (cost is higher than the selling price before fees).';
                }
            }
        }

        return $warnings;
    }
}
