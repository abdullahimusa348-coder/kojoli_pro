@extends('layouts.app')

@section('title', 'Confirm '.$label.' purchase · Nadabo Global Data')

@section('page')
    <a href="{{ route('buy.exam-pin') }}" class="text-sm text-brand-700 hover:underline">← Change plan</a>
    <section class="mt-3 max-w-xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" aria-labelledby="confirm-heading" data-confirm data-exam-pin-confirm>
        <h1 id="confirm-heading" class="text-xl font-semibold text-navy-900">Confirm your purchase</h1>
        <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Service</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $plan->product->service->name }}</dd></div>
            <div><dt class="text-navy-600">Product</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $plan->product->name }}</dd></div>
            <div><dt class="text-navy-600">Plan</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $plan->name }}
                @if ($plan->description)<span class="block text-xs font-normal text-navy-500" data-plan-description>{{ $plan->description }}</span>@endif
            </dd></div>
            <div><dt class="text-navy-600">Quantity</dt><dd class="mt-0.5 font-medium text-navy-900" data-quantity>1 PIN</dd></div>
            <div class="sm:col-span-2 rounded-lg bg-navy-50 px-3 py-2"><dt class="text-navy-600">Amount to pay from your wallet</dt><dd class="mt-0.5 text-lg font-semibold tabular-nums text-navy-900" data-charge>{{ \App\Support\Money::format($amountKobo) }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-navy-600">Wallet balance: <span class="tabular-nums">{{ \App\Support\Money::format($balanceKobo) }}</span></p>
        @if ($balanceKobo < $amountKobo)
            <p class="mt-2 text-sm text-amber-800" data-low-balance>Your wallet balance is lower than this amount.</p>
        @endif
        <p class="mt-2 text-xs text-navy-600" data-result-note>After it is delivered, your PIN is shown only on this purchase's page in your purchases.</p>

        <form method="POST" action="{{ route('buy.exam-pin.store') }}" class="mt-5" x-data="{ sent: false }" x-on:submit="if (sent) $event.preventDefault(); sent = true" data-confirm-form>
            @csrf
            <input type="hidden" name="confirmation" value="{{ $confirmation }}">
            <x-button>Confirm and pay {{ \App\Support\Money::format($amountKobo) }}</x-button>
        </form>
    </section>
@endsection
