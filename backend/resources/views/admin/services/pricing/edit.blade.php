@extends('layouts.admin')

@section('title', 'Edit prices · '.$plan->name.' · Admin · '.config('app.name'))
@section('heading', 'Edit prices')

@php($control = 'block w-full rounded-lg border px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
@php($variable = $plan->isVariable())

@section('page')
    <div class="max-w-3xl">
        <a href="{{ route('admin.services.plans.prices', $plan) }}" class="text-sm text-brand-700 hover:underline">← Back to prices</a>

        <form method="POST" action="{{ route('admin.services.plans.prices.update', $plan) }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate
              x-data="{ copy() { ['price', 'discount', 'fee'].forEach(f => { const src = this.$root.querySelector(`[name='prices[subscriber][${f}]']`); if (! src) return; this.$root.querySelectorAll(`[data-field='${f}']`).forEach(el => { if (el !== src) el.value = src.value; }); }); } }">
            @csrf
            @method('PUT')
            <input type="hidden" name="fingerprint" value="{{ $fingerprint }}">

            <h2 class="break-words text-lg font-semibold text-navy-900">{{ $plan->name }}</h2>
            <p class="mt-0.5 break-words text-sm text-navy-600">{{ $plan->product->name }} · {{ $plan->product->service->name }} · {{ $plan->amount_type->label() }}</p>
            <p class="mt-3 text-sm text-navy-700">
                @if ($variable)
                    The customer enters an amount ({{ $plan->amountLimitsLabel() ?? 'limits not set' }}). Selling price = amount − discount % + fee. Use 0 for no discount or no fee.
                @else
                    Enter the selling price in naira for each customer type.
                @endif
                Leave a type blank to keep it unpriced (that type cannot use this plan). Maximum {{ \App\Support\Money::format($maxAmountKobo) }} (system setting).
            </p>

            @error('prices')<p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $message }}</p>@enderror
            @if ($warnings)
                <div class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" data-price-warnings>
                    <p class="font-semibold">Current prices to check (warning only):</p>
                    <ul class="mt-1 list-disc pl-5">@foreach ($warnings as $warning)<li class="break-words">{{ $warning }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="mt-4 divide-y divide-navy-100 rounded-xl ring-1 ring-navy-100">
                @foreach ($types as $type)
                    @php($price = $prices->get($type->value))
                    @php($t = $type->value)
                    <fieldset class="p-4" data-price-row="{{ $t }}">
                        <legend class="sr-only">{{ $type->label() }}</legend>
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-navy-900">{{ $type->label() }}</p>
                            <span class="text-xs text-navy-500">{{ $price ? ($price->is_active ? 'Active' : 'Disabled') : 'Not priced' }}</span>
                        </div>
                        @if ($variable)
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach (['discount' => ['Discount (%)', \App\Support\Pricing\BasisPoints::toInput($price?->discount_bps), 'e.g. 2.5'], 'fee' => ['Fee (₦)', \App\Support\Pricing\KoboAmount::toInput($price?->fee_kobo), 'e.g. 0']] as $field => [$label, $current, $hint])
                                    @php($key = "prices.$t.$field")
                                    <div>
                                        <label for="{{ $field }}-{{ $t }}" class="mb-1 block text-xs font-medium text-navy-700">{{ $label }}</label>
                                        <input id="{{ $field }}-{{ $t }}" name="prices[{{ $t }}][{{ $field }}]" data-field="{{ $field }}" inputmode="decimal" autocomplete="off" placeholder="{{ $hint }}"
                                               value="{{ old("prices.$t.$field", $current) }}" @class([$control, 'border-red-400' => $errors->has($key), 'border-navy-200' => ! $errors->has($key)])
                                               @if ($errors->has($key)) aria-invalid="true" aria-describedby="{{ $field }}-{{ $t }}-error" @endif>
                                        @error($key)<p id="{{ $field }}-{{ $t }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                                    </div>
                                @endforeach
                            </div>
                        @else
                            @php($key = "prices.$t.price")
                            <label for="price-{{ $t }}" class="mb-1 block text-xs font-medium text-navy-700">Selling price (₦)</label>
                            <input id="price-{{ $t }}" name="prices[{{ $t }}][price]" data-field="price" inputmode="decimal" autocomplete="off" placeholder="e.g. 1,250.00"
                                   value="{{ old("prices.$t.price", \App\Support\Pricing\KoboAmount::toInput($price?->price_kobo)) }}" @class([$control, 'border-red-400' => $errors->has($key), 'border-navy-200' => ! $errors->has($key)])
                                   @if ($errors->has($key)) aria-invalid="true" aria-describedby="price-{{ $t }}-error" @endif>
                            @error($key)<p id="price-{{ $t }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                    </fieldset>
                @endforeach
            </div>

            <button type="button" x-on:click="copy()" class="mt-3 rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50" data-copy-subscriber>Copy Subscriber values to all types</button>
            <p class="mt-1 text-xs text-navy-500">Copies the Subscriber fields into the other rows on this form only. Nothing is saved until you confirm below.</p>

            <label class="mt-5 flex items-start gap-2 text-sm text-navy-800">
                <input type="checkbox" name="confirm" value="1" class="mt-0.5 rounded border-navy-300 text-brand-600 focus:ring-brand-500">
                <span>I have checked these prices. Changes apply immediately and are recorded in the price history.</span>
            </label>
            @error('confirm')<p class="mt-1 text-sm text-red-600" id="confirm-error">{{ $message }}</p>@enderror

            <div class="mt-5 flex justify-end"><x-button class="sm:w-auto">Save prices</x-button></div>
        </form>
    </div>
@endsection
