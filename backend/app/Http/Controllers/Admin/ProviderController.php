<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Providers\SaveProvider;
use App\Actions\Admin\Providers\SetProviderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Providers\ProviderRequest;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Service;
use App\Services\Providers\ProviderAdapterRegistry;
use App\Support\Providers\CredentialKey;
use App\Support\Providers\ProviderStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Providers admin module (providers.*): configuration plus read-only adapter
 * readiness (installed adapter, the services and credentials it needs, base
 * URL host). No API calls. Credential values are never passed to views.
 */
class ProviderController extends Controller
{
    public function index(Request $request, ProviderAdapterRegistry $registry): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ProviderStatus::class)],
        ]);

        $providers = Provider::query()
            ->with('credentials:id,provider_id,key')
            ->withCount(['services', 'routes'])
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('description', 'like', $like));
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->ordered()
            ->paginate(15)
            ->withQueryString();

        return view('admin.providers.index', ['providers' => $providers, 'filters' => $filters, 'statuses' => ProviderStatus::cases(),
            'installedDrivers' => array_keys($registry->adapters())]);
    }

    public function create(): View
    {
        return view('admin.providers.create', ['credentialKeys' => CredentialKey::cases()]);
    }

    public function store(ProviderRequest $request, SaveProvider $save): RedirectResponse
    {
        $provider = $save->create($request->validated(), $request->user('admin'));

        return redirect()->route('admin.providers.show', $provider)->with('status', "Provider “{$provider->name}” created (inactive).");
    }

    public function show(Provider $provider, ProviderAdapterRegistry $registry): View
    {
        $provider->load(['services.service.category', 'credentials.updatedBy', 'routes.plan.product.service']);
        $supported = $provider->services->pluck('service_id');
        $readiness = $registry->providerReadiness($provider);

        return view('admin.providers.show', [
            'provider' => $provider,
            'readiness' => $readiness,
            'adapterServiceNames' => Service::whereIn('slug', $readiness->services)->pluck('name', 'slug'),
            'statuses' => ProviderStatus::cases(),
            'credentialKeys' => CredentialKey::cases(),
            'credentials' => $provider->credentials->keyBy(fn ($c) => $c->key->value),
            'credentialChanges' => $provider->credentialChanges()->with('changedBy')->latest('id')->limit(25)->get(),
            'availableServices' => Service::with('category')->whereNotIn('id', $supported)->ordered()->get(),
            'bulkProducts' => Product::with('service')
                ->whereIn('service_id', $provider->services->where('is_active', true)->pluck('service_id'))
                ->orderBy('service_id')->ordered()->get(),
            'routes' => $provider->routes->sortBy(fn ($r) => [$r->plan->product->service->name, $r->plan->name])->values(),
        ]);
    }

    public function edit(Provider $provider): View
    {
        return view('admin.providers.edit', ['provider' => $provider, 'credentialKeys' => CredentialKey::cases()]);
    }

    public function update(ProviderRequest $request, Provider $provider, SaveProvider $save): RedirectResponse
    {
        $save->update($provider, $request->validated(), $request->user('admin'));

        return redirect()->route('admin.providers.show', $provider)->with('status', 'Provider updated.');
    }

    public function updateStatus(Request $request, Provider $provider, SetProviderStatus $set): RedirectResponse
    {
        $status = ProviderStatus::from($request->validate(['status' => ['required', Rule::enum(ProviderStatus::class)]])['status']);
        $set->handle($provider, $status, $request->user('admin'));

        return back()->with('status', "Provider “{$provider->name}” is now {$status->label()}.");
    }
}
