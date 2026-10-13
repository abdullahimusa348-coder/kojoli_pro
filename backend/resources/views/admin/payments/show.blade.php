@extends('layouts.admin')

@section('title', $payment->reference.' · Payments · Admin · '.config('app.name'))
@section('heading', 'Payment details')

@php($canManage = auth('admin')->user()->can(\App\Support\Enums\SystemPermission::PaymentsManage->value))
@php($card = 'mt-6 max-w-3xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6')

@section('page')
    <a href="{{ route('admin.payments') }}" class="text-sm text-brand-700 hover:underline">← Back to Payments</a>
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif
    @if ($errors->any())
        <x-alert type="error" class="mt-4">{{ $errors->first() }}</x-alert>
    @endif

    <section class="mt-4 max-w-3xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-payment-details>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h2 class="break-all font-mono text-base font-semibold text-navy-900">{{ $payment->reference }}</h2>
            @include('partials.payments.status-badge', ['status' => $payment->status])
        </div>
        <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Customer</dt><dd class="mt-0.5 break-words font-medium text-navy-900">{{ $payment->user->name }} <span class="block break-all text-xs font-normal text-navy-500">{{ $payment->user->email }}</span></dd></div>
            <div><dt class="text-navy-600">Amount</dt><dd class="mt-0.5 font-semibold tabular-nums text-navy-900">{{ \App\Support\Money::format($payment->amount_kobo) }} {{ $payment->currency }}</dd></div>
            <div><dt class="text-navy-600">Gateway</dt><dd class="mt-0.5 break-words font-medium text-navy-900">{{ $payment->gateway->name }} · {{ $payment->mode->label() }}</dd></div>
            <div><dt class="text-navy-600">Gateway reference</dt><dd class="mt-0.5 break-all font-mono text-xs text-navy-900">{{ $payment->gateway_reference ?? '—' }}</dd></div>
            <div><dt class="text-navy-600">Verified amount</dt><dd class="mt-0.5 tabular-nums text-navy-900" data-verified-amount>{{ $payment->verified_amount_kobo !== null ? \App\Support\Money::format($payment->verified_amount_kobo) : '—' }}@if ($payment->verified_at) <span class="block text-xs text-navy-500">checked {{ $payment->verified_at->format('j M Y, H:i:s') }}</span>@endif</dd></div>
            <div><dt class="text-navy-600">Wallet transaction</dt><dd class="mt-0.5 break-all font-mono text-xs text-navy-900" data-wallet-transaction>
                @if ($payment->walletTransaction)
                    @can(\App\Support\Enums\SystemPermission::TransactionsView->value)
                        <a href="{{ route('admin.transactions.show', $payment->walletTransaction) }}" class="text-brand-700 hover:underline">{{ $payment->walletTransaction->reference }}</a>
                    @else
                        {{ $payment->walletTransaction->reference }}
                    @endcan
                @else
                    —
                @endif
            </dd></div>
            <div><dt class="text-navy-600">Created</dt><dd class="mt-0.5 text-navy-900">{{ $payment->created_at?->format('j M Y, H:i:s') }}</dd></div>
            <div><dt class="text-navy-600">Expires / completed</dt><dd class="mt-0.5 text-navy-900">{{ $payment->expires_at?->format('j M Y, H:i') }} / {{ $payment->completed_at?->format('j M Y, H:i') ?? '—' }}</dd></div>
            @if ($payment->failure_reason)
                <div class="sm:col-span-2"><dt class="text-navy-600">Reason</dt><dd class="mt-0.5 break-words text-navy-900" data-failure-reason>{{ $payment->failure_reason }}</dd></div>
            @endif
        </dl>

        @if ($canManage && ! in_array($payment->status, [\App\Support\Payments\PaymentStatus::Successful], true))
            <div class="mt-5 flex flex-wrap gap-2 border-t border-navy-100 pt-4">
                <form method="POST" action="{{ route('admin.payments.recheck', $payment) }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800" data-recheck>Recheck with gateway</button>
                </form>
            </div>
            <p class="mt-2 text-xs text-navy-500">Recheck asks the gateway again. The wallet is credited only if the gateway confirms the exact amount; staff cannot mark a payment paid.</p>
        @endif
    </section>

    @if ($canManage && $payment->status === \App\Support\Payments\PaymentStatus::Review)
        <section class="{{ $card }}" aria-labelledby="review-heading" data-close-review>
            <h2 id="review-heading" class="text-base font-semibold text-navy-900">Close review</h2>
            <p class="mt-0.5 text-sm text-navy-600">Marks the payment failed without crediting the wallet. Any money the customer paid must be settled with the gateway outside this system.</p>
            <form method="POST" action="{{ route('admin.payments.close-review', $payment) }}" class="mt-3">
                @csrf
                <label for="note" class="mb-1 block text-xs font-medium text-navy-700">Internal note</label>
                <textarea id="note" name="note" rows="2" maxlength="250" required class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">{{ old('note') }}</textarea>
                <div class="mt-3 flex justify-end"><button type="submit" class="rounded-lg px-4 py-2 text-sm font-semibold text-red-700 ring-1 ring-red-200 hover:bg-red-50">Close as failed</button></div>
            </form>
        </section>
    @endif

    <section class="{{ $card }}" aria-labelledby="history-heading" data-payment-history>
        <h2 id="history-heading" class="text-base font-semibold text-navy-900">Status history</h2>
        <ul class="mt-3 divide-y divide-navy-100 rounded-xl ring-1 ring-navy-100" role="list">
            @foreach ($payment->statusChanges as $change)
                <li class="px-4 py-2 text-sm" data-status-change="{{ $change->new_status->value }}">
                    <span class="font-medium text-navy-900">{{ $change->old_status ? $change->old_status->label().' → ' : '' }}{{ $change->new_status->label() }}</span>
                    <span class="text-navy-600">· {{ $change->source->label() }}</span>
                    @if ($change->note)<span class="block break-words text-xs text-navy-700">{{ $change->note }}</span>@endif
                    <span class="block text-xs text-navy-500">{{ $change->created_at?->format('j M Y, H:i:s') }}@if ($change->changedBy) · {{ $change->changedBy->name }}@endif</span>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="{{ $card }}" aria-labelledby="webhooks-heading" data-payment-webhooks>
        <h2 id="webhooks-heading" class="text-base font-semibold text-navy-900">Webhooks</h2>
        <p class="mt-0.5 text-sm text-navy-600">Gateway notifications for this payment. They only trigger a server-side check; they never credit a wallet on their own.</p>
        @if ($payment->webhooks->isEmpty())
            <p class="mt-3 text-sm text-navy-500">No webhooks received.</p>
        @else
            <ul class="mt-3 divide-y divide-navy-100 rounded-xl ring-1 ring-navy-100" role="list">
                @foreach ($payment->webhooks as $webhook)
                    <li class="px-4 py-2 text-sm" data-webhook="{{ $webhook->outcome->value }}">
                        <span class="font-medium text-navy-900">{{ $webhook->outcome->label() }}</span>
                        <span class="block break-all text-xs text-navy-500">{{ $webhook->received_at?->format('j M Y, H:i:s') }} · event {{ $webhook->event_key }}{{ $webhook->payload === null ? ' · payload not kept' : '' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
