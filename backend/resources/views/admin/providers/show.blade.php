@extends('layouts.admin')

@section('title', $provider->name.' · Providers · Admin · '.config('app.name'))
@section('heading', 'Provider details')

@php($staff = auth('admin')->user())
@php($canUpdate = $staff->can(\App\Support\Enums\SystemPermission::ProvidersUpdate->value))
@php($canCredentials = $staff->can(\App\Support\Enums\SystemPermission::ProvidersCredentials->value))
@php($canCatalog = $staff->can(\App\Support\Enums\SystemPermission::ServicesView->value))
@php($control = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
@php($card = 'mt-6 max-w-4xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6')

@section('page')
    <a href="{{ route('admin.providers') }}" class="text-sm text-brand-700 hover:underline">← Back to Providers</a>
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif
    @if ($errors->any())
        <x-alert type="error" class="mt-4">{{ $errors->first() }}</x-alert>
    @endif

    <section class="mt-4 max-w-4xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-provider-details>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="break-words text-lg font-semibold text-navy-900">{{ $provider->name }}</h2>
                <div class="mt-1 flex flex-wrap gap-2">
                    @include('admin.providers.status', ['status' => $provider->status])
                    @include('admin.providers.config-badge', ['provider' => $provider])
                </div>
            </div>
            @if ($canUpdate)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('admin.providers.edit', $provider) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">Edit</a>
                    @foreach ($statuses as $status)
                        @continue($status === $provider->status)
                        <form method="POST" action="{{ route('admin.providers.status', $provider) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $status->value }}">
                            <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-navy-800 ring-1 ring-navy-200 hover:bg-navy-50" data-set-status="{{ $status->value }}">
                                {{ match ($status) { \App\Support\Providers\ProviderStatus::Active => 'Activate', \App\Support\Providers\ProviderStatus::Maintenance => 'Set maintenance', \App\Support\Providers\ProviderStatus::Inactive => 'Deactivate' } }}
                            </button>
                        </form>
                    @endforeach
                </div>
            @endif
        </div>
        <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Code</dt><dd class="mt-0.5 break-all font-mono text-navy-900">{{ $provider->code }}</dd></div>
            <div><dt class="text-navy-600">Driver</dt><dd class="mt-0.5 break-all font-mono text-navy-900">{{ $provider->driver ?? '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-navy-600">Configuration</dt><dd class="mt-0.5 break-words text-navy-900" data-configuration>{{ $provider->configurationLabel() }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-navy-600">Base URL</dt><dd class="mt-0.5 break-all text-navy-900">{{ $provider->baseUrl() ?? '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-navy-600">Description</dt><dd class="mt-0.5 break-words text-navy-900">{{ $provider->description ?: '—' }}</dd></div>
        </dl>
        <p class="mt-4 text-xs text-navy-500">Configuration only. No provider API is called until provider integrations are built (Phase 10).</p>
    </section>

    <section class="{{ $card }}" aria-labelledby="services-heading" data-provider-services>
        <h2 id="services-heading" class="text-base font-semibold text-navy-900">Supported services</h2>
        <p class="mt-0.5 text-sm text-navy-600">Routes can only be added for plans in these services. Disabling a service skips its routes without changing them.</p>
        @if ($provider->services->isEmpty())
            <p class="mt-4 text-sm text-navy-500">No services yet.</p>
        @else
            <ul class="mt-4 divide-y divide-navy-100 rounded-xl ring-1 ring-navy-100" role="list">
                @foreach ($provider->services->sortBy(fn ($c) => $c->service->name) as $capability)
                    <li class="space-y-3 p-4" data-capability="{{ $capability->service->slug }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="break-words text-sm font-semibold text-navy-900">{{ $capability->service->name }} <span class="font-normal text-navy-500">· {{ $capability->service->category->name }}</span></p>
                                <p class="break-all text-xs text-navy-600">{{ $capability->requires_plan_code ? 'Requires a provider plan code per route' : 'No per-plan code needed' }}@if ($capability->provider_service_code) · service code {{ $capability->provider_service_code }}@endif</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span @class(['inline-flex rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-green-50 text-green-800' => $capability->is_active, 'bg-navy-50 text-navy-700' => ! $capability->is_active]) data-capability-status>{{ $capability->is_active ? 'Active' : 'Disabled' }}</span>
                                @if ($canUpdate)
                                    <form method="POST" action="{{ route('admin.providers.services.status', [$provider, $capability]) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $capability->is_active ? 0 : 1 }}">
                                        <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-navy-800 ring-1 ring-navy-200 hover:bg-navy-50" data-capability-toggle="{{ $capability->is_active ? 'disable' : 'enable' }}">{{ $capability->is_active ? 'Disable' : 'Enable' }}</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                        @if ($canUpdate)
                            <form method="POST" action="{{ route('admin.providers.services.update', [$provider, $capability]) }}" class="flex flex-wrap items-end gap-3">
                                @csrf
                                @method('PUT')
                                <div class="min-w-0 flex-1">
                                    <label for="service-code-{{ $capability->id }}" class="mb-1 block text-xs font-medium text-navy-700">Provider service code (optional)</label>
                                    <input id="service-code-{{ $capability->id }}" name="provider_service_code" value="{{ $capability->provider_service_code }}" autocomplete="off" class="{{ $control }}">
                                </div>
                                <label class="flex items-center gap-2 pb-2 text-sm text-navy-800">
                                    <input type="checkbox" name="requires_plan_code" value="1" class="rounded border-navy-300 text-brand-600 focus:ring-brand-500" @checked($capability->requires_plan_code)>
                                    Requires plan code
                                </label>
                                <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">Save</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canUpdate && $availableServices->isNotEmpty())
            <form method="POST" action="{{ route('admin.providers.services.store', $provider) }}" class="mt-4 grid gap-3 rounded-xl bg-navy-50/60 p-4 sm:grid-cols-2" data-add-capability>
                @csrf
                <div>
                    <label for="service_id" class="mb-1 block text-xs font-medium text-navy-700">Add a service</label>
                    <select id="service_id" name="service_id" class="{{ $control }}">
                        @foreach ($availableServices as $service)
                            <option value="{{ $service->id }}">{{ $service->name }} ({{ $service->category->name }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="provider_service_code" class="mb-1 block text-xs font-medium text-navy-700">Provider service code (optional)</label>
                    <input id="provider_service_code" name="provider_service_code" autocomplete="off" class="{{ $control }}">
                </div>
                <label class="flex items-center gap-2 text-sm text-navy-800">
                    <input type="checkbox" name="requires_plan_code" value="1" checked class="rounded border-navy-300 text-brand-600 focus:ring-brand-500">
                    Requires a provider plan code per route
                </label>
                <div class="flex justify-end"><button type="submit" class="rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Add service</button></div>
            </form>
        @endif
    </section>

    <section id="routes" class="{{ $card }}" aria-labelledby="routes-heading" data-provider-routes>
        <h2 id="routes-heading" class="text-base font-semibold text-navy-900">Plan routes</h2>
        <p class="mt-0.5 text-sm text-navy-600">Plans that use this provider. Routes are managed on each plan's Provider routes page.</p>
        @if ($routes->isEmpty())
            <p class="mt-4 text-sm text-navy-500">No routes yet.</p>
        @else
            <ul class="mt-4 divide-y divide-navy-100 rounded-xl ring-1 ring-navy-100" role="list">
                @foreach ($routes as $route)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm" data-provider-route="{{ $route->plan->code }}">
                        <div class="min-w-0">
                            @if ($canCatalog)
                                <a href="{{ route('admin.services.plans.routes', $route->plan) }}" class="break-words font-semibold text-navy-900 hover:text-brand-700">{{ $route->plan->name }}</a>
                            @else
                                <span class="break-words font-semibold text-navy-900">{{ $route->plan->name }}</span>
                            @endif
                            <p class="break-all text-xs text-navy-500">{{ $route->plan->product->service->name }} · {{ $route->plan->product->name }} · code {{ $route->provider_plan_code ?? '—' }}</p>
                        </div>
                        <div class="flex items-center gap-2 text-xs">
                            <span class="font-semibold text-navy-700">Priority {{ $route->priority }}</span>
                            <span @class(['inline-flex rounded-full px-2 py-0.5 font-semibold', 'bg-green-50 text-green-800' => $route->is_active, 'bg-navy-50 text-navy-700' => ! $route->is_active])>{{ $route->is_active ? 'Active' : 'Disabled' }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canUpdate && $canCatalog && $bulkProducts->isNotEmpty())
            <form method="POST" action="{{ route('admin.providers.bulk-routes', $provider) }}" class="mt-4 grid gap-3 rounded-xl bg-navy-50/60 p-4 sm:grid-cols-3" data-bulk-routes>
                @csrf
                <div class="sm:col-span-2">
                    <label for="product_id" class="mb-1 block text-xs font-medium text-navy-700">Add this provider to all plans of a product</label>
                    <select id="product_id" name="product_id" class="{{ $control }}">
                        @foreach ($bulkProducts as $product)
                            <option value="{{ $product->id }}">{{ $product->service->name }} · {{ $product->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="priority" class="mb-1 block text-xs font-medium text-navy-700">At priority</label>
                    <input id="priority" name="priority" type="number" min="1" max="999" value="1" class="{{ $control }}">
                </div>
                <p class="text-xs text-navy-600 sm:col-span-2">Creates one route per plan with a blank provider plan code and no cost. Plans that already have this provider or this priority are skipped.</p>
                <div class="flex justify-end"><button type="submit" class="rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Add routes</button></div>
            </form>
        @endif
    </section>

    <section id="credentials" class="{{ $card }}" aria-labelledby="credentials-heading" data-provider-credentials>
        <h2 id="credentials-heading" class="text-base font-semibold text-navy-900">Credentials</h2>
        <p class="mt-0.5 text-sm text-navy-600">Stored encrypted and never shown. Only the last four characters of longer values are displayed as a hint.</p>
        @php($required = array_map(fn ($k) => $k->value, $provider->requiredCredentialKeys()))
        <ul class="mt-4 divide-y divide-navy-100 rounded-xl ring-1 ring-navy-100" role="list">
            @foreach ($credentialKeys as $key)
                @php($credential = $credentials->get($key->value))
                <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm" data-credential="{{ $key->value }}">
                    <div class="min-w-0">
                        <p class="font-semibold text-navy-900">{{ $key->label() }} @if (in_array($key->value, $required, true))<span class="ml-1 rounded bg-navy-50 px-1.5 py-0.5 text-xs font-medium text-navy-700">Required</span>@endif</p>
                        <p class="break-words text-xs text-navy-600" data-credential-state>
                            @if ($credential)
                                Set ({{ $credential->maskedHint() }}) · updated {{ $credential->updated_at?->format('j M Y, H:i') }}@if ($credential->updatedBy) by {{ $credential->updatedBy->name }}@endif
                            @else
                                Not set
                            @endif
                        </p>
                    </div>
                    @if ($credential && $canCredentials)
                        <form method="POST" action="{{ route('admin.providers.credentials.clear', [$provider, $key->value]) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-red-700 ring-1 ring-red-200 hover:bg-red-50" data-credential-clear="{{ $key->value }}">Clear</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($canCredentials)
            <form method="POST" action="{{ route('admin.providers.credentials.update', $provider) }}" class="mt-4 rounded-xl bg-navy-50/60 p-4" autocomplete="off" data-credentials-form>
                @csrf
                @method('PUT')
                <p class="mb-3 text-xs text-navy-600">Enter only the values you want to set or replace. Blank fields keep the current value.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($credentialKeys as $key)
                        <div>
                            <label for="credential-{{ $key->value }}" class="mb-1 block text-xs font-medium text-navy-700">{{ $key->label() }}</label>
                            <input id="credential-{{ $key->value }}" name="credentials[{{ $key->value }}]" type="password" autocomplete="new-password" spellcheck="false" class="{{ $control }}">
                            @error("credentials.{$key->value}")<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 flex justify-end"><button type="submit" class="rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Save credentials</button></div>
            </form>
        @endif

        <h3 class="mt-6 text-sm font-semibold text-navy-900">Credential history</h3>
        @if ($credentialChanges->isEmpty())
            <p class="mt-2 text-sm text-navy-500">No credential changes yet.</p>
        @else
            <ul class="mt-2 divide-y divide-navy-100 rounded-xl ring-1 ring-navy-100" role="list" data-credential-history>
                @foreach ($credentialChanges as $change)
                    <li class="px-4 py-2 text-sm" data-credential-change="{{ $change->action }}">
                        <span class="font-medium text-navy-900">{{ $change->key->label() }}</span> {{ $change->action }}
                        <span class="block text-xs text-navy-500">{{ $change->created_at?->format('j M Y, H:i') }} · {{ $change->changedBy?->name ?? 'System' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
