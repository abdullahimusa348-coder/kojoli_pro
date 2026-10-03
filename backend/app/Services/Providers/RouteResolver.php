<?php

namespace App\Services\Providers;

use App\Models\Plan;
use App\Models\PlanProviderRoute;
use App\Support\Providers\ProviderStatus;

/**
 * Lists a plan's provider routes in priority order and marks each one
 * eligible or skipped (with reasons). Deterministic and side-effect free: it
 * reads only the database, makes no external calls and never tries a
 * provider. Trying routes in order and falling back on failure is Phase 10.
 * No service-specific logic: only the plan, its routes, providers,
 * capabilities and stored credential presence are considered. Provider cost
 * is informational and never affects eligibility.
 */
class RouteResolver
{
    /** @return list<RouteCandidate> */
    public function candidatesFor(Plan $plan): array
    {
        $plan->loadMissing('product.service.category', 'providerRoutes.provider.services', 'providerRoutes.provider.credentials');

        $planReason = $plan->isAvailable() ? null : 'Plan unavailable: '.$plan->statusLabel().'.';
        $serviceId = $plan->product?->service_id;

        return $plan->providerRoutes
            ->sortBy(fn (PlanProviderRoute $r) => [$r->priority, $r->id])
            ->values()
            ->map(function (PlanProviderRoute $route) use ($planReason, $serviceId) {
                $provider = $route->provider;
                $capability = $provider->services->firstWhere('service_id', $serviceId);
                $reasons = [];

                if ($planReason !== null) {
                    $reasons[] = $planReason;
                }
                if (! $route->is_active) {
                    $reasons[] = 'Route disabled.';
                }
                if ($provider->status !== ProviderStatus::Active) {
                    $reasons[] = "Provider {$provider->status->label()}.";
                }
                if ($capability === null) {
                    $reasons[] = 'Provider does not support this service.';
                } elseif (! $capability->is_active) {
                    $reasons[] = 'Provider service disabled.';
                }
                if (($capability?->requires_plan_code ?? true) && blank($route->provider_plan_code)) {
                    $reasons[] = 'Provider plan code required.';
                }
                if (! $provider->isConfigured()) {
                    $reasons[] = 'Provider not configured.';
                }

                return new RouteCandidate($route, $reasons === [], $reasons);
            })
            ->all();
    }

    /** @return list<RouteCandidate> eligible routes only, in the order they would be tried */
    public function eligibleFor(Plan $plan): array
    {
        return array_values(array_filter($this->candidatesFor($plan), fn (RouteCandidate $c) => $c->eligible));
    }
}
