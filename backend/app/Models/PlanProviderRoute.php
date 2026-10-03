<?php

namespace App\Models;

use App\Support\Money;
use App\Support\Pricing\BasisPoints;
use App\Support\Providers\CostType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One provider route for a plan: priority (1 = primary, unique per plan), the
 * provider's own plan code and the optional provider cost. Provider cost is
 * not a selling price (see plan_prices). Changed only through the route
 * actions, which also write the append-only history.
 */
class PlanProviderRoute extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'cost_type' => CostType::class,
            'cost_kobo' => 'integer',
            'cost_discount_bps' => 'integer',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** @return HasMany<PlanProviderRouteChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(PlanProviderRouteChange::class);
    }

    public function hasCost(): bool
    {
        return $this->cost_type !== null;
    }

    public function costLabel(): string
    {
        return self::describeCost($this->cost_type, $this->cost_kobo, $this->cost_discount_bps);
    }

    public static function describeCost(?CostType $type, ?int $kobo, ?int $bps): string
    {
        return match ($type) {
            CostType::Fixed => Money::format((int) $kobo),
            CostType::Percent => BasisPoints::label((int) $bps).' off face value',
            null => 'Cost not set',
        };
    }
}
