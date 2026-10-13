@extends('layouts.app')

@section('title', 'Confirm '.$type->label().' purchase · Nadabo Global Data')

@section('page')
    <a href="{{ route("buy.{$service}") }}" class="text-sm text-brand-700 hover:underline">← Change details</a>
    <section class="mt-3 max-w-xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" aria-labelledby="confirm-heading" data-confirm data-identity-confirm="{{ $service }}">
        <h1 id="confirm-heading" class="text-xl font-semibold text-navy-900">Confirm your purchase</h1>
        <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Service</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $plan->product->service->name }}</dd></div>
            <div><dt class="text-navy-600">Product</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $plan->product->name }}</dd></div>
            <div><dt class="text-navy-600">Plan</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $plan->name }}
                @if ($plan->description)<span class="block text-xs font-normal text-navy-500" data-plan-description>{{ $plan->description }}</span>@endif
            </dd></div>
            {{-- The only page that shows the full number: before payment, to check it. Afterwards only the masked form is shown. --}}
            <div><dt class="text-navy-600">{{ $type->label() }}</dt><dd class="mt-0.5 font-mono tabular-nums text-navy-900" data-recipient>{{ $number }}</dd></div>
            <div class="sm:col-span-2 rounded-lg bg-navy-50 px-3 py-2"><dt class="text-navy-600">Amount to pay from your wallet</dt><dd class="mt-0.5 text-lg font-semibold tabular-nums text-navy-900" data-charge>{{ \App\Support\Money::format($amountKobo) }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-navy-600">Wallet balance: <span class="tabular-nums">{{ \App\Support\Money::format($balanceKobo) }}</span></p>
        @if ($balanceKobo < $amountKobo)
            <p class="mt-2 text-sm text-amber-800" data-low-balance>Your wallet balance is lower than this amount.</p>
        @endif
        <p class="mt-2 text-xs text-navy-600" data-masked-note>After you pay, only the last 4 digits of this {{ $type->label() }} are shown.</p>

        <form method="POST" action="{{ route("buy.{$service}.store") }}" class="mt-5" x-data="{ sent: false }" x-on:submit="if (sent) $event.preventDefault(); sent = true" data-confirm-form>
            @csrf
            <input type="hidden" name="confirmation" value="{{ $confirmation }}">
            <div class="mb-4 flex items-start gap-2">
                <input id="consent" name="consent" type="checkbox" value="1" required class="mt-0.5 rounded border-navy-300 text-brand-600 focus:ring-brand-500"
                    @error('consent') aria-invalid="true" aria-describedby="consent-error" @enderror>
                <label for="consent" class="text-sm text-navy-800" data-consent>{{ $consent }}</label>
            </div>
            @error('consent')<p id="consent-error" class="-mt-2 mb-4 text-sm text-red-600" data-consent-error>{{ $message }}</p>@enderror
            <x-button>Confirm and pay {{ \App\Support\Money::format($amountKobo) }}</x-button>
        </form>
    </section>
@endsection
