<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Providers\SaveProviderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Providers\ProviderServiceRequest;
use App\Models\Provider;
use App\Models\ProviderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Provider capabilities (services a provider supports). providers.update; no delete. */
class ProviderServiceController extends Controller
{
    public function store(ProviderServiceRequest $request, Provider $provider, SaveProviderService $save): RedirectResponse
    {
        $capability = $save->create($provider, $request->validated() + ['requires_plan_code' => $request->boolean('requires_plan_code')], $request->user('admin'));

        return back()->with('status', "{$capability->service->name} added to {$provider->name}.");
    }

    public function update(ProviderServiceRequest $request, Provider $provider, ProviderService $capability, SaveProviderService $save): RedirectResponse
    {
        $this->ensureBelongs($provider, $capability);
        $save->update($capability, $request->validated() + ['requires_plan_code' => $request->boolean('requires_plan_code')], $request->user('admin'));

        return back()->with('status', "{$capability->service->name} settings saved.");
    }

    public function updateStatus(Request $request, Provider $provider, ProviderService $capability, SaveProviderService $save): RedirectResponse
    {
        $this->ensureBelongs($provider, $capability);
        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $save->setActive($capability, $active, $request->user('admin'));

        return back()->with('status', $active
            ? "{$capability->service->name} enabled for {$provider->name}."
            : "{$capability->service->name} disabled for {$provider->name}. Its routes are skipped until it is enabled again.");
    }

    private function ensureBelongs(Provider $provider, ProviderService $capability): void
    {
        abort_unless($capability->provider_id === $provider->id, 404);
    }
}
