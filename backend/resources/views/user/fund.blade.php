@extends('layouts.app')

@section('title', 'Fund wallet · Nadabo Global Data')

@section('page')
    <a href="{{ route('wallet') }}" class="text-sm text-brand-700 hover:underline">← Back to Wallet</a>
    <section class="mt-3" aria-labelledby="fund-heading">
        <h1 id="fund-heading" class="text-2xl font-semibold text-navy-900 sm:text-3xl">Fund wallet</h1>
        <p class="mt-1 text-sm text-navy-600">Pay securely on the payment provider's page. Your wallet is credited once the provider confirms the payment.</p>
    </section>

    <section class="mt-6 max-w-xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-fund-card>
        @if ($gateways->isEmpty())
            <p class="text-sm text-navy-700" data-fund-unavailable>Wallet funding is not available right now. Please try again later.</p>
        @else
            <form method="POST" action="{{ route('wallet.fund.store') }}" novalidate data-fund-form>
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ $token }}">
                <x-input name="amount" label="Amount (₦)" inputmode="decimal" autocomplete="off" placeholder="e.g. 1000" required />
                <p class="-mt-2 mb-4 text-xs text-navy-600" data-fund-limits>From {{ \App\Support\Money::format($minKobo) }} to {{ \App\Support\Money::format($maxKobo) }}. No extra charges are added.</p>

                @if ($gateways->count() === 1)
                    <input type="hidden" name="gateway" value="{{ $gateways->first()->id }}">
                    <p class="mb-4 text-sm text-navy-700">Payment method: <span class="font-semibold">{{ $gateways->first()->name }}</span></p>
                @else
                    <fieldset class="mb-4">
                        <legend class="mb-1 text-sm font-medium text-navy-800">Payment method</legend>
                        <div class="grid gap-2">
                            @foreach ($gateways as $gateway)
                                <label class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-navy-800 ring-1 ring-navy-200">
                                    <input type="radio" name="gateway" value="{{ $gateway->id }}" class="border-navy-300 text-brand-600 focus:ring-brand-500" @checked((string) old('gateway', $loop->first ? $gateway->id : '') === (string) $gateway->id)>
                                    {{ $gateway->name }}
                                </label>
                            @endforeach
                        </div>
                        @error('gateway')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </fieldset>
                @endif
                @error('idempotency_key')<p class="mb-3 text-sm text-red-600">{{ $message }}</p>@enderror
                <x-button>Continue to payment</x-button>
            </form>
        @endif
    </section>

    <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="payments-heading" data-payment-history>
        <h2 id="payments-heading" class="border-b border-navy-100 px-5 py-3 text-base font-semibold text-navy-900">Payment history</h2>
        @if ($payments->isEmpty())
            <p class="px-5 py-6 text-sm text-navy-500">No payments yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($payments as $payment)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm" data-payment="{{ $payment->reference }}">
                        <div class="min-w-0">
                            <a href="{{ route('wallet.fund.show', $payment->reference) }}" class="block break-all font-mono text-xs font-semibold text-brand-700 hover:underline">{{ $payment->reference }}</a>
                            <p class="text-xs text-navy-500">{{ $payment->created_at?->format('j M Y, H:i') }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            @include('partials.payments.status-badge', ['status' => $payment->status])
                            <span class="font-semibold tabular-nums text-navy-900">{{ \App\Support\Money::format($payment->amount_kobo) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
            @include('admin.services.partials.pagination', ['paginator' => $payments])
        @endif
    </section>
@endsection
