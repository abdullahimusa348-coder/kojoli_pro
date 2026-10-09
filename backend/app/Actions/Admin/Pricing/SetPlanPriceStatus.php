<?php

namespace App\Actions\Admin\Pricing;

use App\Models\PlanPrice;
use App\Models\SystemUser;
use Illuminate\Support\Facades\DB;

/**
 * Enables or disables one customer type's price (pricing.update). A disabled
 * price makes the plan unavailable for that type; there is no fallback to
 * another type. Each change is recorded in the price history.
 */
class SetPlanPriceStatus
{
    public function __construct(private PricingRules $rules) {}

    public function handle(PlanPrice $price, bool $active, SystemUser $actor): void
    {
        $this->rules->authorizeUpdate($actor);

        DB::transaction(function () use ($price, $active, $actor) {
            $price = PlanPrice::whereKey($price->id)->lockForUpdate()->firstOrFail();
            if ($price->is_active === $active) {
                return;
            }

            $old = $price->only(['price_kobo', 'discount_bps', 'fee_kobo', 'is_active']);
            $price->forceFill(['is_active' => $active, 'updated_by' => $actor->id])->save();
            SavePlanPrices::record($price, $old, $actor);
        });
    }
}
