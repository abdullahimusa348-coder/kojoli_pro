@extends('layouts.admin')

@section('title', $gateway->name.' · Gateways · Admin · '.config('app.name'))
@section('heading', 'Gateway details')

@php($staff = auth('admin')->user())
@php($canGateways = $staff->can(\App\Support\Enums\SystemPermission::PaymentsGateways->value))
@php($canCredentials = $staff->can(\App\Support\Enums\SystemPermission::PaymentsCredentials->value))
@php($control = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
@php($card = 'mt-6 max-w-4xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6')

@section('page')
    <a href="{{ route('admin.payments.gateways') }}" class="text-sm text-brand-700 hover:underline">← Back to Gateways</a>
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif
    @if ($errors->any())
        <x-alert type="error" class="mt-4">{{ $errors->first() }}</x-alert>
    @endif

    <section class="mt-4 max-w-4xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-gateway-details>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="break-words text-lg font-semibold text-navy-900">{{ $gateway->name }}</h2>
                <div class="mt-1 flex flex-wrap gap-2">@include('admin.payments.gateways.status', ['gateway' => $gateway, 'problem' => $problem])</div>
            </div>
            @if ($canGateways)
                <div class="flex flex-wrap gap-2">
                    @foreach ($statuses as $status)
                        @continue($status === $gateway->status)
                        <form method="POST" action="{{ route('admin.payments.gateways.status', $gateway) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $status->value }}">
                            <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-navy-800 ring-1 ring-navy-200 hover:bg-navy-50" data-set-status="{{ $status->value }}">
                                {{ match ($status) { \App\Support\Payments\GatewayStatus::Active => 'Activate', \App\Support\Payments\GatewayStatus::Maintenance => 'Set maintenance', \App\Support\Payments\GatewayStatus::Inactive => 'Deactivate' } }}
                            </button>
                        </form>
                    @endforeach
                </div>
            @endif
        </div>
        <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Code</dt><dd class="mt-0.5 break-all font-mono text-navy-900">{{ $gateway->code }}</dd></div>
            <div><dt class="text-navy-600">Driver</dt><dd class="mt-0.5 break-all text-navy-900">{{ $adapter?->label() ?? $gateway->driver.' (not installed)' }}</dd></div>
            <div><dt class="text-navy-600">Priority</dt><dd class="mt-0.5 text-navy-900">{{ $gateway->priority }}</dd></div>
            <div><dt class="text-navy-600">Wallet funding</dt><dd class="mt-0.5 text-navy-900">{{ $gateway->wallet_funding ? 'Yes' : 'No' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-navy-600">Configuration</dt><dd class="mt-0.5 break-words text-navy-900" data-configuration>{{ $problem ?? 'Ready for '.$gateway->mode->label().' payments' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-navy-600">Webhook address (give this to the gateway)</dt><dd class="mt-0.5 break-all font-mono text-xs text-navy-900" data-webhook-url>{{ route('webhooks.payments', $gateway->code) }}</dd></div>
            @if ($adapter)
                <div class="sm:col-span-2"><dt class="text-navy-600">Allowed checkout hosts ({{ $gateway->mode->label() }})</dt><dd class="mt-0.5 break-all text-xs text-navy-900">{{ implode(', ', $adapter->checkoutHosts($gateway->mode)) ?: '—' }}</dd></div>
            @endif
        </dl>
        <p class="mt-4 text-xs text-navy-500">Endpoints and allowed hosts are fixed by the driver in code and cannot be edited here.</p>
    </section>

    @if ($canGateways)
        <section class="{{ $card }}" aria-labelledby="edit-heading" data-gateway-edit>
            <h2 id="edit-heading" class="text-base font-semibold text-navy-900">Details</h2>
            <form method="POST" action="{{ route('admin.payments.gateways.update', $gateway) }}" class="mt-3" novalidate>
                @csrf
                @method('PUT')
                <x-input name="name" label="Name" :value="$gateway->name" autocomplete="off" required />
                <input type="hidden" name="wallet_funding" value="0">
                <label class="mb-4 flex items-center gap-2 text-sm text-navy-800">
                    <input type="checkbox" name="wallet_funding" value="1" class="rounded border-navy-300 text-brand-600 focus:ring-brand-500" @checked(old('wallet_funding', $gateway->wallet_funding ? '1' : '0') === '1')>
                    Use for wallet funding
                </label>
                @foreach ($adapter?->settingsRules() ?? [] as $key => $rules)
                    <div class="mb-4">
                        <label for="setting-{{ $key }}" class="mb-1 block text-sm font-medium text-navy-800">{{ Str::headline($key) }}</label>
                        <input id="setting-{{ $key }}" name="settings[{{ $key }}]" value="{{ old("settings.$key", $gateway->settings[$key] ?? '') }}" autocomplete="off" class="{{ $control }}">
                        @error("settings.$key")<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                @endforeach
                <div class="flex justify-end"><button type="submit" class="rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Save details</button></div>
            </form>
        </section>

        <section class="{{ $card }}" aria-labelledby="mode-heading" data-gateway-mode-form>
            <h2 id="mode-heading" class="text-base font-semibold text-navy-900">Mode</h2>
            <p class="mt-0.5 text-sm text-navy-600">Sandbox uses test credentials. Live takes real money: it needs live payments switched on in Settings (currently {{ $liveEnabled ? 'on' : 'off' }}) and every live credential. A live gateway never falls back to sandbox.</p>
            @if ($gateway->mode === \App\Support\Payments\GatewayMode::Live)
                <form method="POST" action="{{ route('admin.payments.gateways.mode', $gateway) }}" class="mt-3">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="mode" value="sandbox">
                    <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-navy-800 ring-1 ring-navy-200 hover:bg-navy-50" data-set-mode="sandbox">Switch to sandbox</button>
                </form>
            @else
                @if ($liveProblem)<p class="mt-2 break-words text-sm text-amber-800" data-live-problem>Cannot go live yet: {{ $liveProblem }}.</p>@endif
                <form method="POST" action="{{ route('admin.payments.gateways.mode', $gateway) }}" class="mt-3 flex flex-wrap items-center gap-3">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="mode" value="live">
                    <label class="flex items-center gap-2 text-sm text-navy-800">
                        <input type="checkbox" name="confirm_live" value="1" class="rounded border-navy-300 text-red-600 focus:ring-red-500">
                        I confirm this gateway should take real payments
                    </label>
                    <button type="submit" class="rounded-lg px-4 py-2 text-sm font-semibold text-red-700 ring-1 ring-red-200 hover:bg-red-50" data-set-mode="live">Switch to live</button>
                </form>
            @endif
        </section>
    @endif

    <section id="credentials" class="{{ $card }}" aria-labelledby="credentials-heading" data-gateway-credentials>
        <h2 id="credentials-heading" class="text-base font-semibold text-navy-900">Credentials</h2>
        <p class="mt-0.5 text-sm text-navy-600">Stored encrypted and never shown. Only the last four characters of longer values are displayed as a hint. Sandbox and live credentials are kept separately.</p>
        @if (! $adapter)
            <p class="mt-3 text-sm text-amber-800">The driver for this gateway is not installed, so its credentials cannot be managed.</p>
        @else
            @foreach ($modes as $mode)
                @php($required = $adapter->requiredCredentials($mode))
                <h3 class="mt-5 text-sm font-semibold text-navy-900">{{ $mode->label() }}</h3>
                <ul class="mt-2 divide-y divide-navy-100 rounded-xl ring-1 ring-navy-100" role="list">
                    @foreach ($adapter->credentialKeys() as $key)
                        @php($credential = $gateway->credentials->first(fn ($c) => $c->mode === $mode && $c->key === $key))
                        <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm" data-credential="{{ $mode->value }}.{{ $key }}">
                            <div class="min-w-0">
                                <p class="break-all font-semibold text-navy-900">{{ Str::headline($key) }} @if (in_array($key, $required, true))<span class="ml-1 rounded bg-navy-50 px-1.5 py-0.5 text-xs font-medium text-navy-700">Required</span>@endif</p>
                                <p class="break-words text-xs text-navy-600" data-credential-state>
                                    @if ($credential)
                                        Set ({{ $credential->maskedHint() }}) · updated {{ $credential->updated_at?->format('j M Y, H:i') }}@if ($credential->updatedBy) by {{ $credential->updatedBy->name }}@endif
                                    @else
                                        Not set
                                    @endif
                                </p>
                            </div>
                            @if ($credential && $canCredentials)
                                <form method="POST" action="{{ route('admin.payments.gateways.credentials.clear', [$gateway, $mode->value, $key]) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-red-700 ring-1 ring-red-200 hover:bg-red-50" data-credential-clear="{{ $mode->value }}.{{ $key }}">Clear</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($canCredentials)
                    <form method="POST" action="{{ route('admin.payments.gateways.credentials.update', [$gateway, $mode->value]) }}" class="mt-3 rounded-xl bg-navy-50/60 p-4" autocomplete="off" data-credentials-form="{{ $mode->value }}">
                        @csrf
                        @method('PUT')
                        <p class="mb-3 text-xs text-navy-600">Enter only the {{ strtolower($mode->label()) }} values you want to set or replace. Blank fields keep the current value.</p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($adapter->credentialKeys() as $key)
                                <div>
                                    <label for="credential-{{ $mode->value }}-{{ $key }}" class="mb-1 block text-xs font-medium text-navy-700">{{ Str::headline($key) }}</label>
                                    <input id="credential-{{ $mode->value }}-{{ $key }}" name="credentials[{{ $key }}]" type="password" autocomplete="new-password" spellcheck="false" class="{{ $control }}">
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-3 flex justify-end"><button type="submit" class="rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Save {{ strtolower($mode->label()) }} credentials</button></div>
                    </form>
                @endif
            @endforeach
        @endif

        <h3 class="mt-6 text-sm font-semibold text-navy-900">Credential history</h3>
        @if ($credentialChanges->isEmpty())
            <p class="mt-2 text-sm text-navy-500">No credential changes yet.</p>
        @else
            <ul class="mt-2 divide-y divide-navy-100 rounded-xl ring-1 ring-navy-100" role="list" data-credential-history>
                @foreach ($credentialChanges as $change)
                    <li class="px-4 py-2 text-sm" data-credential-change="{{ $change->action }}">
                        <span class="font-medium text-navy-900">{{ Str::headline($change->key) }}</span> ({{ $change->mode->label() }}) {{ $change->action }}
                        <span class="block text-xs text-navy-500">{{ $change->created_at?->format('j M Y, H:i') }} · {{ $change->changedBy?->name ?? 'System' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
