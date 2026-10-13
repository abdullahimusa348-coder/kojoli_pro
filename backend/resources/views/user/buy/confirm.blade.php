@extends('layouts.app')

@section('title', 'Confirm purchase · Nadabo Global Data')

@section('page')
    <a href="{{ route('buy.service', [$service, 'network' => $plan->product->network?->value]) }}" class="text-sm text-brand-700 hover:underline">← Change details</a>
    <section class="mt-3 max-w-xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" aria-labelledby="confirm-heading" data-confirm>
        <h1 id="confirm-heading" class="text-xl font-semibold text-navy-900">Confirm your purchase</h1>
        <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Service</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $plan->product->service->name }}</dd></div>
            <div><dt class="text-navy-600">Network</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $plan->product->network?->label() ?? $plan->product->name }}</dd></div>
            <div><dt class="text-navy-600">{{ $plan->isVariable() ? 'Airtime' : 'Plan' }}</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $plan->isVariable() ? \App\Support\Money::format($quote->faceValueKobo) : $plan->name }}
                @if (! $plan->isVariable() && ($plan->dataVolumeLabel() || $plan->validityLabel()))<span class="block text-xs font-normal text-navy-500">{{ collect([$plan->dataVolumeLabel(), $plan->validityLabel()])->filter()->implode(' · ') }}</span>@endif
            </dd></div>
            <div><dt class="text-navy-600">Recipient</dt><dd class="mt-0.5 font-mono tabular-nums text-navy-900" data-recipient>{{ $phone }}</dd></div>
            @if ($plan->isVariable())
                <div><dt class="text-navy-600">Discount</dt><dd class="mt-0.5 tabular-nums text-navy-900">{{ \App\Support\Money::format($quote->discountKobo ?? 0) }}</dd></div>
                <div><dt class="text-navy-600">Fee</dt><dd class="mt-0.5 tabular-nums text-navy-900">{{ \App\Support\Money::format($quote->feeKobo ?? 0) }}</dd></div>
            @endif
            <div class="sm:col-span-2 rounded-lg bg-navy-50 px-3 py-2"><dt class="text-navy-600">Amount to pay from your wallet</dt><dd class="mt-0.5 text-lg font-semibold tabular-nums text-navy-900" data-charge>{{ \App\Support\Money::format($quote->amountKobo) }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-navy-600">Wallet balance: <span class="tabular-nums">{{ \App\Support\Money::format($balanceKobo) }}</span></p>
        @if ($balanceKobo < $quote->amountKobo)
            <p class="mt-2 text-sm text-amber-800" data-low-balance>Your wallet balance is lower than this amount.</p>
        @endif

        <form method="POST" action="{{ route('buy.store', $service) }}" class="mt-5" data-confirm-form>
            @csrf
            <input type="hidden" name="plan" value="{{ $plan->id }}">
            <input type="hidden" name="phone" value="{{ $phone }}">
            @if ($plan->isVariable())<input type="hidden" name="face_value_kobo" value="{{ $quote->faceValueKobo }}">@endif
            <input type="hidden" name="confirmed_amount_kobo" value="{{ $quote->amountKobo }}">
            <input type="hidden" name="token" value="{{ $token }}">
            <x-button x-data="{ sent: false }" x-on:click="if (sent) $event.preventDefault(); sent = true">Confirm and pay {{ \App\Support\Money::format($quote->amountKobo) }}</x-button>
        </form>
    </section>
@endsection
