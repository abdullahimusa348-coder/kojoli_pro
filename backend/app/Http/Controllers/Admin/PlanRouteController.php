<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Providers\SavePlanRoute;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Providers\PlanRouteRequest;
use App\Models\Plan;
use App\Models\PlanProviderRoute;
use App\Models\PlanProviderRouteChange;
use App\Models\Provider;
use App\Services\Providers\ProviderAdapterRegistry;
use App\Services\Providers\RouteCostWarnings;
use App\Services\Providers\RouteResolver;
use App\Support\Pricing\BasisPoints;
use App\Support\Pricing\KoboAmount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Provider routes of one plan (Services area): services.view + providers.view;
 * changes need providers.update. Configuration, routing preview and whether
 * each route can run now (Phase 7 eligibility plus the provider's adapter);
 * nothing is sent to any provider from here.
 */
class PlanRouteController extends Controller
{
    public function index(Plan $plan, RouteResolver $resolver, ProviderAdapterRegistry $registry): View
    {
        $plan->load('product.service.category', 'activePrices');
        $candidates = $resolver->candidatesFor($plan);
        $routed = collect($candidates)->map(fn ($c) => $c->route->provider_id);

        return view('admin.services.routes.index', [
            'plan' => $plan,
            'candidates' => $candidates,
            'readiness' => array_map(fn ($candidate) => $registry->routeReadiness($candidate), $candidates),
            'costWarnings' => RouteCostWarnings::for($plan, collect($candidates)->map->route),
            'providers' => Provider::whereHas('services', fn ($q) => $q->where('service_id', $plan->product->service_id)->where('is_active', true))
                ->whereNotIn('id', $routed)->ordered()->get(),
            'nextPriority' => (collect($candidates)->max(fn ($c) => $c->route->priority) ?? 0) + 1,
            'history' => PlanProviderRouteChange::where('plan_id', $plan->id)->with('provider', 'changedBy')->latest('id')->limit(25)->get(),
        ]);
    }

    public function store(PlanRouteRequest $request, Plan $plan, SavePlanRoute $save): RedirectResponse
    {
        $provider = Provider::findOrFail($request->validated('provider_id'));
        $save->create($plan, $provider, ['priority' => (int) $request->validated('priority')] + $request->routeData(), $request->user('admin'));

        return redirect()->route('admin.services.plans.routes', $plan)->with('status', "Route to {$provider->name} added.");
    }

    public function edit(Plan $plan, PlanProviderRoute $route): View
    {
        $this->ensureBelongs($plan, $route);

        return view('admin.services.routes.edit', [
            'plan' => $plan->load('product.service'),
            'route' => $route->load('provider'),
            'costInput' => KoboAmount::toInput($route->cost_kobo),
            'discountInput' => BasisPoints::toInput($route->cost_discount_bps),
        ]);
    }

    public function update(PlanRouteRequest $request, Plan $plan, PlanProviderRoute $route, SavePlanRoute $save): RedirectResponse
    {
        $this->ensureBelongs($plan, $route);
        $changed = $save->update($route, $request->routeData(), $request->user('admin'));

        return redirect()->route('admin.services.plans.routes', $plan)->with('status', $changed ? 'Route updated.' : 'No route changes to save.');
    }

    public function updateStatus(Request $request, Plan $plan, PlanProviderRoute $route, SavePlanRoute $save): RedirectResponse
    {
        $this->ensureBelongs($plan, $route);
        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $save->setActive($route, $active, $request->user('admin'));

        return back()->with('status', $active ? 'Route enabled.' : 'Route disabled. It is skipped until enabled again.');
    }

    public function move(Request $request, Plan $plan, PlanProviderRoute $route, SavePlanRoute $save): RedirectResponse
    {
        $this->ensureBelongs($plan, $route);
        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];
        $moved = $save->move($route, $direction, $request->user('admin'));

        return back()->with('status', $moved ? 'Route order updated.' : 'This route is already '.($direction === 'up' ? 'first.' : 'last.'));
    }

    private function ensureBelongs(Plan $plan, PlanProviderRoute $route): void
    {
        abort_unless($route->plan_id === $plan->id, 404);
    }
}
