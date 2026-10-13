@extends('layouts.admin')

@section('title', 'Prices · '.$plan->name.' · Admin · '.config('app.name'))
@section('heading', 'Plan prices')

@php($canUpdate = auth('admin')->user()->can(\App\Support\Enums\SystemPermission::PricingUpdate->value))

@section('page')
    <a href="{{ route('admin.services.plans.show', $plan) }}" class="text-sm text-brand-700 hover:underline">← Back to plan</a>
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif
    @if ($errors->any())
        <x-alert type="error" class="mt-4">{{ $errors->first() }}</x-alert>
    @endif

    <section class="mt-4 max-w-3xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-pricing-plan>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="break-words text-lg font-semibold text-navy-900">{{ $plan->name }}</h2>
                <p class="mt-0.5 break-words text-sm text-navy-600">{{ $plan->product->name }} · {{ $plan->product->service->name }} · {{ $plan->amount_type->label() }}</p>
            </div>
            @if ($canUpdate)
                <a href="{{ route('admin.services.plans.prices.edit', $plan) }}" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-700">Edit prices</a>
            @endif
        </div>
        <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Plan status</dt><dd class="mt-0.5">@include('admin.services.partials.status', ['label' => $plan->statusLabel()])</dd></div>
            <div><dt class="text-navy-600">Customer types priced</dt><dd class="mt-0.5">@include('admin.services.pricing.priced-badge', ['plan' => $plan])</dd></div>
            @if ($plan->isVariable())
                <div class="sm:col-span-2"><dt class="text-navy-600">Amount limits (face value)</dt><dd class="mt-0.5 font-medium text-navy-900" data-amount-limits>{{ $plan->amountLimitsLabel() ?? 'Not set: set them on the plan before pricing it' }}</dd></div>
            @endif
        </dl>
        <p class="mt-4 text-xs text-navy-500">Customer selling prices only. Each customer type is priced on its own: a type without an active price cannot use this plan (no fallback to another type).</p>
    </section>

    @if ($warnings)
        <section class="mt-4 max-w-3xl rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200" data-price-warnings>
            <p class="font-semibold">Check these prices (warning only, nothing was changed):</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                @foreach ($warnings as $warning)
                    <li class="break-words">{{ $warning }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="mt-6 max-w-3xl overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="prices-heading">
        <h2 id="prices-heading" class="border-b border-navy-100 px-5 py-3 text-base font-semibold text-navy-900">Prices by customer type</h2>
        <ul class="divide-y divide-navy-100" role="list">
            @foreach ($types as $type)
                @php($price = $prices->get($type->value))
                <li class="flex flex-wrap items-center gap-3 px-5 py-3" data-price="{{ $type->value }}">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-navy-900">{{ $type->label() }}</p>
                        <p class="break-words text-sm text-navy-700" data-price-value>{{ $price ? $price->summary() : 'Not priced' }}</p>
                    </div>
                    <span @class([
                        'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                        'bg-green-50 text-green-800' => $price?->is_active,
                        'bg-navy-50 text-navy-700' => $price && ! $price->is_active,
                        'bg-amber-50 text-amber-800' => ! $price,
                    ]) data-price-status>{{ $price ? ($price->is_active ? 'Active' : 'Disabled') : 'Missing' }}</span>
                    @if ($price && $canUpdate)
                        <form method="POST" action="{{ route('admin.services.plans.prices.status', [$plan, $type->value]) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="is_active" value="{{ $price->is_active ? 0 : 1 }}">
                            <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 {{ $price->is_active ? 'text-navy-800 ring-navy-200 hover:bg-navy-50' : 'text-green-800 ring-green-200 hover:bg-green-50' }}" data-price-toggle="{{ $price->is_active ? 'disable' : 'enable' }}">{{ $price->is_active ? 'Disable' : 'Enable' }}</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    @php($control = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
    <section class="mt-6 max-w-3xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-price-preview>
        <h2 class="text-base font-semibold text-navy-900">Price preview</h2>
        <p class="mt-0.5 text-sm text-navy-600">Shows what a customer of the chosen type would be charged now, including availability checks.</p>
        <form method="GET" action="{{ route('admin.services.plans.prices', $plan) }}" class="mt-4 grid gap-3 sm:grid-cols-3">
            <div>
                <label for="preview_type" class="mb-1 block text-xs font-medium text-navy-700">Customer type</label>
                <select id="preview_type" name="preview_type" class="{{ $control }}">
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(($preview['preview_type'] ?? '') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>
            @if ($plan->isVariable())
                <div>
                    <label for="preview_amount" class="mb-1 block text-xs font-medium text-navy-700">Amount (₦)</label>
                    <input id="preview_amount" name="preview_amount" inputmode="decimal" value="{{ $preview['preview_amount'] ?? '' }}" placeholder="e.g. 1,000" class="{{ $control }}">
                </div>
            @endif
            <div class="flex items-end">
                <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Preview</button>
            </div>
        </form>
        @if ($previewError)
            <p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" data-quote="error">{{ $previewError }}</p>
        @elseif ($quote)
            @if ($quote->available)
                <div class="mt-4 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-900" data-quote="available">
                    <p><span class="font-semibold">{{ $quote->userType->label() }} pays {{ \App\Support\Money::format($quote->amountKobo) }}</span></p>
                    @if ($quote->faceValueKobo !== null)
                        <p class="mt-0.5 break-words text-green-800">Amount {{ \App\Support\Money::format($quote->faceValueKobo) }} − discount {{ \App\Support\Money::format($quote->discountKobo) }} + fee {{ \App\Support\Money::format($quote->feeKobo) }}</p>
                    @endif
                </div>
            @else
                <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" data-quote="unavailable">Not available to {{ $quote->userType->label() }}: {{ $quote->reason }}</p>
            @endif
        @endif
    </section>

    <section class="mt-6 max-w-3xl overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="history-heading" data-price-history>
        <h2 id="history-heading" class="border-b border-navy-100 px-5 py-3 text-base font-semibold text-navy-900">Price history</h2>
        @if ($history->isEmpty())
            <p class="px-5 py-6 text-sm text-navy-500">No price changes yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($history as $change)
                    <li class="px-5 py-3 text-sm" data-price-change="{{ $change->user_type->value }}">
                        <p class="break-words text-navy-900"><span class="font-semibold">{{ $change->user_type->label() }}:</span> {{ $change->oldSummary() }} → {{ $change->newSummary() }}</p>
                        <p class="text-xs text-navy-500">{{ $change->created_at?->format('j M Y, H:i') }} · {{ $change->changedBy?->name ?? 'System' }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
