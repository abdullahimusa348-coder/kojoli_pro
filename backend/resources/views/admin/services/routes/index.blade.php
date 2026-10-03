@extends('layouts.admin')

@section('title', 'Provider routes · '.$plan->name.' · Admin · '.config('app.name'))
@section('heading', 'Provider routes')

@php($canUpdate = auth('admin')->user()->can(\App\Support\Enums\SystemPermission::ProvidersUpdate->value))
@php($control = 'block w-full rounded-lg border px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
@php($last = count($candidates) - 1)

@section('page')
    <a href="{{ route('admin.services.plans.show', $plan) }}" class="text-sm text-brand-700 hover:underline">← Back to plan</a>
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif

    <section class="mt-4 max-w-4xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-routes-plan>
        <h2 class="break-words text-lg font-semibold text-navy-900">{{ $plan->name }}</h2>
        <p class="mt-0.5 break-words text-sm text-navy-600">{{ $plan->product->name }} · {{ $plan->product->service->name }} · {{ $plan->amount_type->label() }} · <span class="break-all font-mono">{{ $plan->code }}</span></p>
        <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
            @include('admin.services.partials.status', ['label' => $plan->statusLabel()])
            @include('admin.services.routes.badge', ['candidates' => $candidates])
        </div>
        <p class="mt-3 text-xs text-navy-500">Routes are tried in priority order (1 = primary) once provider integrations exist (Phase 10). Nothing is sent to any provider now. Provider cost is informational and never affects eligibility.</p>
    </section>

    <section class="mt-6 max-w-4xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-routing-preview>
        <h2 class="text-base font-semibold text-navy-900">Routing preview</h2>
        @php($eligible = array_values(array_filter($candidates, fn ($c) => $c->eligible)))
        <p class="mt-1 break-words text-sm text-navy-800" data-preview-order>
            @if ($eligible === [])
                No eligible provider.
            @else
                Will try: {{ collect($eligible)->map(fn ($c, $i) => ($i + 1).' '.$c->route->provider->name)->implode(' → ') }}
            @endif
        </p>
    </section>

    @if ($costWarnings)
        <section class="mt-4 max-w-4xl rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200" data-cost-warnings>
            <p class="font-semibold">Cost warnings (information only; nothing is blocked):</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                @foreach ($costWarnings as $warning)<li class="break-words">{{ $warning }}</li>@endforeach
            </ul>
        </section>
    @endif

    <section class="mt-6 max-w-4xl overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="routes-heading">
        <h2 id="routes-heading" class="border-b border-navy-100 px-5 py-3 text-base font-semibold text-navy-900">Routes</h2>
        @if ($candidates === [])
            <p class="px-5 py-6 text-sm text-navy-500">No provider routes yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($candidates as $i => $candidate)
                    @php($route = $candidate->route)
                    <li class="space-y-2 px-5 py-4" data-route="{{ $route->provider->code }}" data-priority="{{ $route->priority }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="break-words text-sm font-semibold text-navy-900"><span class="mr-1 rounded bg-navy-900 px-1.5 py-0.5 text-xs text-white">{{ $route->priority }}</span> {{ $route->provider->name }}</p>
                                <p class="break-all text-xs text-navy-600">Code: {{ $route->provider_plan_code ?? '—' }} · Cost: <span data-route-cost>{{ $route->costLabel() }}</span></p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span @class(['inline-flex rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-green-50 text-green-800' => $candidate->eligible, 'bg-amber-50 text-amber-800' => ! $candidate->eligible]) data-route-eligibility>{{ $candidate->eligible ? 'Eligible' : 'Skipped' }}</span>
                                <span @class(['inline-flex rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-green-50 text-green-800' => $route->is_active, 'bg-navy-50 text-navy-700' => ! $route->is_active])>{{ $route->is_active ? 'Active' : 'Disabled' }}</span>
                            </div>
                        </div>
                        @if (! $candidate->eligible)
                            <ul class="list-disc pl-5 text-xs text-amber-800" data-route-reasons>
                                @foreach ($candidate->reasons as $reason)<li class="break-words">{{ $reason }}</li>@endforeach
                            </ul>
                        @endif
                        @if ($canUpdate)
                            <div class="flex flex-wrap gap-2">
                                @foreach (['up' => 'Move up', 'down' => 'Move down'] as $direction => $label)
                                    @if (($direction === 'up' && $i > 0) || ($direction === 'down' && $i < $last))
                                        <form method="POST" action="{{ route('admin.services.plans.routes.move', [$plan, $route]) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="direction" value="{{ $direction }}">
                                            <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-navy-800 ring-1 ring-navy-200 hover:bg-navy-50" data-move="{{ $direction }}">{{ $label }}</button>
                                        </form>
                                    @endif
                                @endforeach
                                <a href="{{ route('admin.services.plans.routes.edit', [$plan, $route]) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">Edit</a>
                                <form method="POST" action="{{ route('admin.services.plans.routes.status', [$plan, $route]) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $route->is_active ? 0 : 1 }}">
                                    <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 {{ $route->is_active ? 'text-navy-800 ring-navy-200 hover:bg-navy-50' : 'text-green-800 ring-green-200 hover:bg-green-50' }}" data-route-toggle="{{ $route->is_active ? 'disable' : 'enable' }}">{{ $route->is_active ? 'Disable' : 'Enable' }}</button>
                                </form>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($canUpdate)
        <section class="mt-6 max-w-4xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-add-route>
            <h2 class="text-base font-semibold text-navy-900">Add route</h2>
            @if ($providers->isEmpty())
                <p class="mt-1 text-sm text-navy-500">No other provider has an active service for {{ $plan->product->service->name }}. Add the service on a provider's page first.</p>
            @else
                <form method="POST" action="{{ route('admin.services.plans.routes.store', $plan) }}" class="mt-3 grid gap-3 sm:grid-cols-2" novalidate>
                    @csrf
                    <div>
                        <label for="provider_id" class="mb-1 block text-xs font-medium text-navy-700">Provider</label>
                        <select id="provider_id" name="provider_id" @class([$control, 'border-red-400' => $errors->has('provider_id'), 'border-navy-200' => ! $errors->has('provider_id')])>
                            @foreach ($providers as $provider)
                                <option value="{{ $provider->id }}" @selected((string) old('provider_id') === (string) $provider->id)>{{ $provider->name }} ({{ $provider->status->label() }})</option>
                            @endforeach
                        </select>
                        @error('provider_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="priority" class="mb-1 block text-xs font-medium text-navy-700">Priority (1 = primary)</label>
                        <input id="priority" name="priority" type="number" min="1" max="999" value="{{ old('priority', $nextPriority) }}" @class([$control, 'border-red-400' => $errors->has('priority'), 'border-navy-200' => ! $errors->has('priority')])>
                        @error('priority')<p id="priority-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    @include('admin.services.routes.fields', ['codeValue' => old('provider_plan_code'), 'costValue' => old('cost'), 'discountValue' => old('cost_discount')])
                    <div class="flex justify-end sm:col-span-2"><button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Add route</button></div>
                </form>
            @endif
        </section>
    @endif

    <section class="mt-6 max-w-4xl overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="route-history-heading" data-route-history>
        <h2 id="route-history-heading" class="border-b border-navy-100 px-5 py-3 text-base font-semibold text-navy-900">Route history</h2>
        @if ($history->isEmpty())
            <p class="px-5 py-6 text-sm text-navy-500">No route changes yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($history as $change)
                    <li class="px-5 py-3 text-sm" data-route-change="{{ $change->event }}">
                        <p class="break-words text-navy-900"><span class="font-semibold">{{ $change->provider->name }}:</span> {{ $change->summary() }}</p>
                        <p class="text-xs text-navy-500">{{ $change->created_at?->format('j M Y, H:i') }} · {{ $change->changedBy?->name ?? 'System' }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
