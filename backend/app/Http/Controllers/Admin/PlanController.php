<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Catalog\SavePlan;
use App\Actions\Admin\Catalog\SetCatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalog\PlanRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Support\Catalog\AmountType;
use App\Support\Catalog\Network;
use App\Support\Catalog\ValidityPeriod;
use App\Support\Enums\SystemPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Catalog plans (Services area, Plans tab). Structure only: no prices,
 * provider routes or purchasing. Uses the services.* permissions.
 */
class PlanController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'service' => ['nullable', 'integer', Rule::exists('services', 'id')],
            'product' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'network' => ['nullable', Rule::enum(Network::class)],
            'validity' => ['nullable', Rule::enum(ValidityPeriod::class)],
            'status' => ['nullable', 'in:active,disabled,available'],
            'pricing' => ['nullable', 'in:missing,complete'],
        ]);
        // Price information is only shown to (and filterable by) staff with pricing.view.
        $pricing = $request->user('admin')->can(SystemPermission::PricingView->value);

        $plans = Plan::query()
            ->with('product.service.category')
            ->when($pricing, fn ($query) => $query->withCount('activePrices'))
            ->when($pricing && ($filters['pricing'] ?? null) === 'missing', fn ($query) => $query->has('activePrices', '<', Plan::customerTypeCount()))
            ->when($pricing && ($filters['pricing'] ?? null) === 'complete', fn ($query) => $query->has('activePrices', '>=', Plan::customerTypeCount()))
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('description', 'like', $like));
            })
            ->when($filters['product'] ?? null, fn ($query, $product) => $query->where('product_id', $product))
            ->when($filters['service'] ?? null, fn ($query, $service) => $query->whereHas('product', fn ($q) => $q->where('service_id', $service)))
            ->when($filters['network'] ?? null, fn ($query, $network) => $query->whereHas('product', fn ($q) => $q->where('network', $network)))
            ->when($filters['validity'] ?? null, fn ($query, $validity) => $query->where('validity_period', $validity))
            ->when(($filters['status'] ?? null) === 'available', fn ($query) => $query->available())
            ->when(in_array($filters['status'] ?? null, ['active', 'disabled'], true), fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('product_id')
            ->ordered()
            ->paginate(15)
            ->withQueryString();

        return view('admin.services.plans.index', [
            'plans' => $plans,
            'filters' => $filters,
            'services' => Service::ordered()->get(['id', 'name']),
            'products' => Product::with('service')->orderBy('service_id')->ordered()->get(),
            'networks' => Network::cases(),
            'validities' => ValidityPeriod::cases(),
        ]);
    }

    public function create(): View
    {
        return view('admin.services.plans.create', $this->formData());
    }

    public function store(PlanRequest $request, SavePlan $save): RedirectResponse
    {
        $plan = $save->create($request->planData(), $request->user('admin'));

        return redirect()->route('admin.services.plans.show', $plan)->with('status', "Plan “{$plan->name}” created.");
    }

    public function show(Plan $plan): View
    {
        return view('admin.services.plans.show', ['plan' => $plan->load('product.service.category')]);
    }

    public function edit(Plan $plan): View
    {
        return view('admin.services.plans.edit', ['plan' => $plan] + $this->formData());
    }

    public function update(PlanRequest $request, Plan $plan, SavePlan $save): RedirectResponse
    {
        $save->update($plan, $request->planData(), $request->user('admin'));

        return redirect()->route('admin.services.plans.show', $plan)->with('status', 'Plan updated.');
    }

    public function updateStatus(Request $request, Plan $plan, SetCatalogStatus $set): RedirectResponse
    {
        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $set->handle($plan, (bool) $active, $request->user('admin'));

        return back()->with('status', $active ? "Plan “{$plan->name}” enabled." : "Plan “{$plan->name}” disabled.");
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'products' => Product::with('service')->orderBy('service_id')->ordered()->get(),
            'amountTypes' => AmountType::cases(),
            'validities' => ValidityPeriod::cases(),
        ];
    }
}
