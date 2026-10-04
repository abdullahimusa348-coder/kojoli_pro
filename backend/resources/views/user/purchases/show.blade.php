@extends('layouts.app')

@section('title', 'Purchase · Nadabo Global Data')

@php($status = $purchase->status)
@section('page')
    <a href="{{ route('purchases') }}" class="text-sm text-brand-700 hover:underline">← My purchases</a>
    <section class="mt-3 max-w-xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" aria-labelledby="result-heading" data-purchase-result="{{ $status->value }}">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h1 id="result-heading" class="text-xl font-semibold text-navy-900">
                {{ match ($status) {
                    \App\Support\Purchases\PurchaseStatus::Successful => 'Purchase successful',
                    \App\Support\Purchases\PurchaseStatus::Pending => 'Purchase pending',
                    \App\Support\Purchases\PurchaseStatus::Failed => 'Purchase not completed',
                    \App\Support\Purchases\PurchaseStatus::Review => 'Purchase under review',
                } }}
            </h1>
            @include('partials.purchases.customer-status-badge', ['status' => $status])
        </div>
        <p class="mt-2 text-sm text-navy-700" data-result-message>
            {{ match ($status) {
                \App\Support\Purchases\PurchaseStatus::Successful => 'Your purchase was delivered.',
                \App\Support\Purchases\PurchaseStatus::Pending => 'We are waiting for confirmation. This usually takes a few minutes; there is no need to buy again.',
                \App\Support\Purchases\PurchaseStatus::Failed => 'This purchase did not go through. The amount has been returned to your wallet.',
                \App\Support\Purchases\PurchaseStatus::Review => 'We are still confirming this purchase. If it is confirmed as not delivered, the amount is returned to your wallet automatically.',
            } }}
        </p>

        <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Reference</dt><dd class="mt-0.5 break-all font-mono text-xs text-navy-900">{{ $purchase->reference }}</dd></div>
            <div><dt class="text-navy-600">Service</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $purchase->service_name }} · {{ $purchase->network?->label() ?? $purchase->product_name }}</dd></div>
            <div><dt class="text-navy-600">{{ $purchase->amount_type === \App\Support\Catalog\AmountType::Variable ? 'Airtime' : 'Plan' }}</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $purchase->amount_type === \App\Support\Catalog\AmountType::Variable ? \App\Support\Money::format($purchase->face_value_kobo) : $purchase->plan_name }}</dd></div>
            <div><dt class="text-navy-600">Recipient</dt><dd class="mt-0.5 font-mono tabular-nums text-navy-900">{{ $purchase->recipient }}</dd></div>
            <div><dt class="text-navy-600">Amount charged</dt><dd class="mt-0.5 font-semibold tabular-nums text-navy-900" data-charged>{{ \App\Support\Money::format($purchase->amount_kobo) }}</dd></div>
            <div><dt class="text-navy-600">Date</dt><dd class="mt-0.5 text-navy-900">{{ $purchase->created_at?->format('j M Y, H:i') }}</dd></div>
        </dl>

        <div class="mt-5 flex flex-wrap gap-2">
            @if (! $purchase->isFinal())
                <a href="{{ route('purchases.show', $purchase->reference) }}" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Refresh</a>
            @endif
            <a href="{{ route('wallet') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-800 ring-1 ring-navy-200 hover:bg-navy-50">Go to wallet</a>
        </div>

        @if ($autoRefreshSeconds !== null)
            {{-- Reloads once after the interval; the server decides again on every load. Hidden without JavaScript, where Refresh still works. --}}
            <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-navy-50 px-3 py-2 text-sm text-navy-700" role="status" x-cloak
                 x-data="{ on: true, timer: null }" x-init="timer = setTimeout(() => window.location.reload(), {{ $autoRefreshSeconds * 1000 }})"
                 data-auto-refresh="{{ $autoRefreshSeconds }}">
                <p x-show="on">This page checks for updates every {{ $autoRefreshSeconds }} seconds.</p>
                <button type="button" x-show="on" x-on:click="on = false; clearTimeout(timer)" class="font-semibold text-brand-700 underline hover:text-brand-800" data-auto-refresh-stop>Stop</button>
                <p x-show="! on" data-auto-refresh-stopped>Automatic updates stopped. Use Refresh to check again.</p>
            </div>
        @endif
    </section>
@endsection
