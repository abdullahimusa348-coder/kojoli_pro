<?php

namespace App\Actions\Admin\Providers;

use App\Models\Plan;
use App\Models\PlanProviderRoute;
use App\Models\PlanProviderRouteChange;
use App\Models\Provider;
use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use App\Support\Pricing\BasisPoints;
use App\Support\Pricing\PricingLimits;
use App\Support\Providers\CostType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates, edits, enables/disables and reorders plan provider routes
 * (providers.update), writing one append-only history row per change.
 * Priority is unique per plan; Move Up/Down swaps two routes inside a
 * transaction. The provider of a route never changes.
 */
class SavePlanRoute
{
    public function __construct(private ProviderRules $rules) {}

    /** @param  array{priority: int, provider_plan_code?: ?string, cost_type?: ?CostType, cost_kobo?: ?int, cost_discount_bps?: ?int}  $data */
    public function create(Plan $plan, Provider $provider, array $data, SystemUser $actor, string $event = 'created'): PlanProviderRoute
    {
        $this->rules->authorize($actor, SystemPermission::ProvidersUpdate);

        return DB::transaction(function () use ($plan, $provider, $data, $actor, $event) {
            $taken = PlanProviderRoute::where('plan_id', $plan->id)->lockForUpdate()->get();
            if ($taken->contains('provider_id', $provider->id)) {
                throw ValidationException::withMessages(['provider_id' => 'This provider already has a route for this plan.']);
            }
            if ($taken->contains('priority', (int) $data['priority'])) {
                throw ValidationException::withMessages(['priority' => "Priority {$data['priority']} is already used by another route for this plan."]);
            }

            $route = (new PlanProviderRoute)->forceFill([
                'plan_id' => $plan->id,
                'provider_id' => $provider->id,
                'priority' => (int) $data['priority'],
                'provider_plan_code' => $data['provider_plan_code'] ?? null,
                'is_active' => true,
                'updated_by' => $actor->id,
            ] + $this->cost($plan, $data));
            $route->save();
            self::record($route, null, $event, $actor);

            return $route;
        });
    }

    /** @param  array{provider_plan_code?: ?string, cost_type?: ?CostType, cost_kobo?: ?int, cost_discount_bps?: ?int}  $data */
    public function update(PlanProviderRoute $route, array $data, SystemUser $actor): bool
    {
        $this->rules->authorize($actor, SystemPermission::ProvidersUpdate);

        return DB::transaction(function () use ($route, $data, $actor) {
            $route = PlanProviderRoute::whereKey($route->id)->lockForUpdate()->firstOrFail();
            $old = self::snapshot($route);
            $route->forceFill(['provider_plan_code' => $data['provider_plan_code'] ?? null] + $this->cost($route->plan, $data));
            if (! $route->isDirty()) {
                return false;
            }
            $route->forceFill(['updated_by' => $actor->id])->save();
            self::record($route, $old, 'updated', $actor);

            return true;
        });
    }

    public function setActive(PlanProviderRoute $route, bool $active, SystemUser $actor): void
    {
        $this->rules->authorize($actor, SystemPermission::ProvidersUpdate);

        DB::transaction(function () use ($route, $active, $actor) {
            $route = PlanProviderRoute::whereKey($route->id)->lockForUpdate()->firstOrFail();
            if ($route->is_active === $active) {
                return;
            }
            $old = self::snapshot($route);
            $route->forceFill(['is_active' => $active, 'updated_by' => $actor->id])->save();
            self::record($route, $old, $active ? 'enabled' : 'disabled', $actor);
        });
    }

    /** Swaps the route with its neighbour above ("up") or below ("down"). Returns false at either end. */
    public function move(PlanProviderRoute $route, string $direction, SystemUser $actor): bool
    {
        $this->rules->authorize($actor, SystemPermission::ProvidersUpdate);
        if (! in_array($direction, ['up', 'down'], true)) {
            throw new InvalidArgumentException('Direction must be up or down.');
        }

        return DB::transaction(function () use ($route, $direction, $actor) {
            $routes = PlanProviderRoute::where('plan_id', $route->plan_id)->orderBy('priority')->lockForUpdate()->get()->values();
            $index = $routes->search(fn ($r) => $r->id === $route->id);
            $neighbour = $routes->get($direction === 'up' ? $index - 1 : $index + 1);
            if ($index === false || $neighbour === null) {
                return false;
            }
            $current = $routes[$index];
            [$a, $b] = [$current->priority, $neighbour->priority];
            $oldCurrent = self::snapshot($current);
            $oldNeighbour = self::snapshot($neighbour);

            // Temporary priority 0 (never used otherwise) keeps the (plan, priority) unique index valid.
            $current->forceFill(['priority' => 0])->save();
            $neighbour->forceFill(['priority' => $a, 'updated_by' => $actor->id])->save();
            $current->forceFill(['priority' => $b, 'updated_by' => $actor->id])->save();

            self::record($current, $oldCurrent, 'moved', $actor);
            self::record($neighbour, $oldNeighbour, 'moved', $actor);

            return true;
        });
    }

    /**
     * Cost columns for the plan's amount type (domain guard; the form request validates input first).
     *
     * @return array{cost_type: ?CostType, cost_kobo: ?int, cost_discount_bps: ?int}
     */
    private function cost(Plan $plan, array $data): array
    {
        $kobo = $data['cost_kobo'] ?? null;
        $bps = $data['cost_discount_bps'] ?? null;
        if ($kobo === null && $bps === null) {
            return ['cost_type' => null, 'cost_kobo' => null, 'cost_discount_bps' => null];
        }

        $valid = $plan->isVariable()
            ? $kobo === null && $bps >= 0 && $bps <= BasisPoints::MAX
            : $bps === null && $kobo >= 1 && $kobo <= PricingLimits::maxAmountKobo();
        if (! $valid) {
            throw new InvalidArgumentException('Invalid provider cost for this plan.');
        }

        return $plan->isVariable()
            ? ['cost_type' => CostType::Percent, 'cost_kobo' => null, 'cost_discount_bps' => $bps]
            : ['cost_type' => CostType::Fixed, 'cost_kobo' => $kobo, 'cost_discount_bps' => null];
    }

    /** @return array<string, mixed> */
    private static function snapshot(PlanProviderRoute $route): array
    {
        return [
            'priority' => $route->priority,
            'provider_plan_code' => $route->provider_plan_code,
            'is_active' => $route->is_active,
            'cost_type' => $route->cost_type,
            'cost_kobo' => $route->cost_kobo,
            'cost_discount_bps' => $route->cost_discount_bps,
        ];
    }

    /** @param  array<string, mixed>|null  $old */
    private static function record(PlanProviderRoute $route, ?array $old, string $event, SystemUser $actor): void
    {
        (new PlanProviderRouteChange)->forceFill([
            'plan_provider_route_id' => $route->id,
            'plan_id' => $route->plan_id,
            'provider_id' => $route->provider_id,
            'event' => $event,
            'old_priority' => $old['priority'] ?? null,
            'new_priority' => $route->priority,
            'old_provider_plan_code' => $old['provider_plan_code'] ?? null,
            'new_provider_plan_code' => $route->provider_plan_code,
            'old_is_active' => $old['is_active'] ?? null,
            'new_is_active' => $route->is_active,
            'old_cost_type' => $old['cost_type'] ?? null,
            'new_cost_type' => $route->cost_type,
            'old_cost_kobo' => $old['cost_kobo'] ?? null,
            'new_cost_kobo' => $route->cost_kobo,
            'old_cost_discount_bps' => $old['cost_discount_bps'] ?? null,
            'new_cost_discount_bps' => $route->cost_discount_bps,
            'changed_by' => $actor->id,
        ])->save();
    }
}
