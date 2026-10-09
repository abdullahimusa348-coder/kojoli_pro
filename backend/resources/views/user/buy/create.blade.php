@extends('layouts.app')

@section('title', 'Buy '.$label.' · Nadabo Global Data')

@php($control = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
@section('page')
    <a href="{{ route('buy') }}" class="text-sm text-brand-700 hover:underline">← Back to Buy</a>
    <section class="mt-3" aria-labelledby="buy-heading">
        <h1 id="buy-heading" class="text-2xl font-semibold text-navy-900 sm:text-3xl">Buy {{ $label }}</h1>
        <p class="mt-1 text-sm text-navy-600">Wallet balance: <span class="font-semibold tabular-nums text-navy-900" data-balance>{{ \App\Support\Money::format($balanceKobo) }}</span></p>
    </section>

    @if ($errors->has('purchase') && ! $maintenance)
        <x-alert type="error" class="mt-4" data-purchase-error>{{ $errors->first('purchase') }}</x-alert>
    @endif

    <section class="mt-6 max-w-xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-buy-card>
        @if ($maintenance)
            @include('partials.purchases.maintenance-notice')
        @elseif ($plans->isEmpty())
            <p class="text-sm text-navy-700" data-unavailable>Not available right now. Please try again later.</p>
        @else
            <form method="GET" action="{{ route('buy.service', $service) }}" class="mb-5" data-network-picker>
                <fieldset>
                    <legend class="mb-2 text-sm font-medium text-navy-800">Network</legend>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($networks as $item)
                            <button type="submit" name="network" value="{{ $item->value }}" data-network="{{ $item->value }}"
                                @class(['rounded-lg px-3 py-1.5 text-sm font-medium ring-1', 'bg-navy-900 text-white ring-navy-900' => $network === $item, 'text-navy-800 ring-navy-200 hover:bg-navy-50' => $network !== $item])
                                @if ($network === $item) aria-pressed="true" @endif>{{ $item->label() }}</button>
                        @endforeach
                    </div>
                </fieldset>
            </form>

            <form method="POST" action="{{ route('buy.confirm', $service) }}" novalidate data-buy-form>
                @csrf
                @if ($service === 'data')
                    <fieldset class="mb-4">
                        <legend class="mb-2 text-sm font-medium text-navy-800">Plan</legend>
                        <div class="grid gap-2">
                            @foreach ($plans as $row)
                                <label class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm ring-1 ring-navy-200" data-plan="{{ $row['plan']->id }}">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <input type="radio" name="plan" value="{{ $row['plan']->id }}" class="border-navy-300 text-brand-600 focus:ring-brand-500" @checked((string) old('plan') === (string) $row['plan']->id)>
                                        <span class="min-w-0 break-words text-navy-900">{{ $row['plan']->name }}
                                            <span class="block text-xs text-navy-500">{{ collect([$row['plan']->dataVolumeLabel(), $row['plan']->validityLabel()])->filter()->implode(' · ') }}</span>
                                        </span>
                                    </span>
                                    <span class="shrink-0 font-semibold tabular-nums text-navy-900">{{ \App\Support\Money::format($row['quote']->amountKobo) }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('plan')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </fieldset>
                @else
                    @php($airtime = $plans->first()['plan'])
                    <input type="hidden" name="plan" value="{{ $airtime->id }}">
                    <x-input name="amount" label="Airtime amount (₦)" inputmode="decimal" autocomplete="off" placeholder="e.g. 500" required />
                    <p class="-mt-2 mb-4 text-xs text-navy-600" data-amount-limits>From {{ \App\Support\Money::format($airtime->min_amount_kobo) }} to {{ \App\Support\Money::format($airtime->max_amount_kobo) }}.</p>
                    @error('plan')<p class="mb-3 text-sm text-red-600">{{ $message }}</p>@enderror
                @endif

                <x-input name="phone" label="Phone number" type="tel" inputmode="tel" autocomplete="tel" placeholder="08012345678" required />
                <x-button>Continue</x-button>
            </form>
        @endif
    </section>
@endsection
