@extends('layouts.admin')

@section('title', $purchase->reference.' · Purchases · Admin · '.config('app.name'))
@section('heading', 'Purchase details')

@php($canManage = auth('admin')->user()->can(\App\Support\Enums\SystemPermission::PurchasesManage->value))
@php($canTransactions = auth('admin')->user()->can(\App\Support\Enums\SystemPermission::TransactionsView->value))
@php($card = 'mt-6 max-w-4xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6')
@php($money = fn (?int $kobo) => $kobo === null ? '—' : \App\Support\Money::format($kobo))

@section('page')
    <a href="{{ route('admin.purchases') }}" class="text-sm text-brand-700 hover:underline">← Back to Purchases</a>
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif
    @if ($errors->any())
        <x-alert type="error" class="mt-4">{{ $errors->first() }}</x-alert>
    @endif

    <section class="mt-4 max-w-4xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" aria-labelledby="customer-heading" data-purchase-details>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 id="customer-heading" class="break-all font-mono text-base font-semibold text-navy-900">{{ $purchase->reference }}</h2>
                <p class="mt-0.5 text-xs text-navy-500">What the customer bought and was charged</p>
            </div>
            @include('partials.purchases.status-badge', ['status' => $purchase->status])
        </div>
        <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Customer</dt><dd class="mt-0.5 break-words font-medium text-navy-900">{{ $purchase->user->name }} <span class="block break-all text-xs font-normal text-navy-500">{{ $purchase->user->email }}</span></dd></div>
            <div><dt class="text-navy-600">Customer type at purchase</dt><dd class="mt-0.5 text-navy-900">{{ $purchase->user_type->label() }}</dd></div>
            <div><dt class="text-navy-600">Service · product · plan</dt><dd class="mt-0.5 break-words font-medium text-navy-900">{{ $purchase->service_name }} · {{ $purchase->product_name }} · {{ $purchase->plan_name }}</dd></div>
            <div><dt class="text-navy-600">Network</dt><dd class="mt-0.5 text-navy-900">{{ $purchase->network?->label() ?? '—' }}</dd></div>
            <div><dt class="text-navy-600">{{ $purchase->recipient_type?->isIdentity() ? $purchase->recipient_type->label() : 'Recipient' }}</dt><dd class="mt-0.5 font-mono tabular-nums text-navy-900" data-recipient>{{ $purchase->displayRecipient() }}</dd></div>
            <div><dt class="text-navy-600">Face value</dt><dd class="mt-0.5 tabular-nums text-navy-900">{{ $money($purchase->face_value_kobo) }}</dd></div>
            <div><dt class="text-navy-600">Discount / fee</dt><dd class="mt-0.5 tabular-nums text-navy-900">{{ $money($purchase->discount_kobo) }} / {{ $money($purchase->fee_kobo) }}</dd></div>
            <div><dt class="text-navy-600">Amount charged</dt><dd class="mt-0.5 font-semibold tabular-nums text-navy-900" data-amount>{{ $money($purchase->amount_kobo) }}</dd></div>
            <div><dt class="text-navy-600">Created</dt><dd class="mt-0.5 text-navy-900">{{ $purchase->created_at?->format('j M Y, H:i:s') }}</dd></div>
            <div><dt class="text-navy-600">Completed</dt><dd class="mt-0.5 text-navy-900">{{ $purchase->completed_at?->format('j M Y, H:i:s') ?? '—' }}</dd></div>
@if ($purchase->recipient_type?->isIdentity())
            {{-- NIN/BVN only (Phase 11 CP3): staff see only whether a result is stored and its field count; result values are shown to the customer only.
                 The directives start at column 0 so phone purchase pages render exactly as before. --}}
            <div><dt class="text-navy-600">Result</dt><dd class="mt-0.5 text-navy-900" data-result-summary>{{ $resultFieldCount === null ? 'None stored' : 'Stored · '.$resultFieldCount.' '.\Illuminate\Support\Str::plural('field', $resultFieldCount).' (shown to the customer only)' }}</dd></div>
@endif
            @foreach (['Debit' => $purchase->debitTransaction, 'Refund' => $purchase->refundTransaction] as $label => $tx)
                <div><dt class="text-navy-600">{{ $label }} transaction</dt><dd class="mt-0.5 break-all font-mono text-xs text-navy-900" data-{{ strtolower($label) }}-transaction>
                    @if ($tx)
                        @if ($canTransactions)<a href="{{ route('admin.transactions.show', $tx) }}" class="text-brand-700 hover:underline">{{ $tx->reference }}</a>@else{{ $tx->reference }}@endif
                    @else
                        —
                    @endif
                </dd></div>
            @endforeach
        </dl>

        @if ($canManage && ! $purchase->isFinal())
            <div class="mt-5 border-t border-navy-100 pt-4">
                <form method="POST" action="{{ route('admin.purchases.recheck', $purchase) }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800" data-recheck>Re-check with provider</button>
                </form>
                <p class="mt-2 text-xs text-navy-500">Asks the provider again using its documented status check. Only a definite provider answer settles the purchase; staff cannot mark it successful or refund it by hand.</p>
            </div>
        @elseif ($purchase->isFinal())
            <p class="mt-5 border-t border-navy-100 pt-4 text-xs text-navy-500" data-final-note>This purchase is {{ strtolower($purchase->status->label()) }} and can no longer change.</p>
        @endif
    </section>

    <section class="{{ $card }} ring-amber-200" aria-labelledby="internal-heading" data-internal>
        <h2 id="internal-heading" class="text-base font-semibold text-navy-900">Internal: providers and money</h2>
        <p class="mt-0.5 text-sm text-navy-600">Staff only. Never shown to customers. Provider credentials and raw provider responses are never stored or shown.</p>
        <dl class="mt-4 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
            <div><dt class="text-navy-600">Provider cost</dt><dd class="mt-0.5 tabular-nums text-navy-900" data-cost>{{ $money($purchase->cost_kobo) }}</dd></div>
            <div><dt class="text-navy-600">Margin</dt><dd class="mt-0.5 tabular-nums text-navy-900" data-margin>{{ $money($purchase->margin_kobo) }}</dd></div>
            <div><dt class="text-navy-600">Re-checks / next check</dt><dd class="mt-0.5 text-navy-900">{{ $purchase->check_count }} / {{ $purchase->next_check_at?->format('j M Y, H:i') ?? '—' }}</dd></div>
            @if ($purchase->failure_reason)
                <div class="sm:col-span-3"><dt class="text-navy-600">Reason</dt><dd class="mt-0.5 break-words text-navy-900" data-failure-reason>{{ $purchase->failure_reason }}</dd></div>
            @endif
        </dl>

        <h3 class="mt-6 text-sm font-semibold text-navy-900">Provider attempts</h3>
        @if ($purchase->attempts->isEmpty())
            <p class="mt-2 text-sm text-navy-500">No provider was called.</p>
        @else
            <ul class="mt-2 divide-y divide-navy-100 rounded-xl ring-1 ring-navy-100" role="list">
                @foreach ($purchase->attempts as $attempt)
                    <li class="px-4 py-3 text-sm" data-attempt="{{ $attempt->attempt_number }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="break-words font-medium text-navy-900">#{{ $attempt->attempt_number }} · {{ $attempt->provider->name }} · route {{ $attempt->route_priority }}@if ($attempt->provider_plan_code) · code <span class="font-mono">{{ $attempt->provider_plan_code }}</span>@endif</p>
                            <span @class(['inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-green-50 text-green-800' => $attempt->status === \App\Support\Purchases\PurchaseAttemptStatus::Succeeded,
                                'bg-red-50 text-red-800' => $attempt->status === \App\Support\Purchases\PurchaseAttemptStatus::FailedDefinite,
                                'bg-amber-50 text-amber-800' => in_array($attempt->status, [\App\Support\Purchases\PurchaseAttemptStatus::Unknown, \App\Support\Purchases\PurchaseAttemptStatus::Started], true),
                            ]) data-attempt-status="{{ $attempt->status->value }}">{{ $attempt->status->label() }}</span>
                        </div>
                        <p class="mt-1 break-all text-xs text-navy-600">Our reference <span class="font-mono">{{ $attempt->request_reference }}</span> · provider reference <span class="font-mono">{{ $attempt->provider_reference ?? '—' }}</span></p>
                        <p class="break-words text-xs text-navy-500">Started {{ $attempt->started_at?->format('j M Y, H:i:s') }} · finished {{ $attempt->finished_at?->format('H:i:s') ?? '—' }}@if ($attempt->duration_ms !== null) · {{ number_format($attempt->duration_ms) }} ms @endif · last checked {{ $attempt->last_checked_at?->format('j M Y, H:i') ?? '—' }} · cost {{ $money($attempt->costKobo($purchase->face_value_kobo)) }}</p>
                        @if ($attempt->error_code || $attempt->error_message)
                            <p class="break-words text-xs text-navy-700">{{ $attempt->error_code }}@if ($attempt->error_code && $attempt->error_message): @endif{{ $attempt->error_message }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="{{ $card }}" aria-labelledby="history-heading" data-purchase-history>
        <h2 id="history-heading" class="text-base font-semibold text-navy-900">Status history</h2>
        <ul class="mt-3 divide-y divide-navy-100 rounded-xl ring-1 ring-navy-100" role="list">
            @foreach ($purchase->statusChanges as $change)
                <li class="px-4 py-2 text-sm" data-status-change="{{ $change->new_status->value }}">
                    <span class="font-medium text-navy-900">{{ $change->old_status ? $change->old_status->label().' → ' : '' }}{{ $change->new_status->label() }}</span>
                    <span class="text-navy-600">· {{ $change->source->label() }}</span>
                    @if ($change->note)<span class="block break-words text-xs text-navy-700">{{ $change->note }}</span>@endif
                    <span class="block text-xs text-navy-500">{{ $change->created_at?->format('j M Y, H:i:s') }}@if ($change->changedBy) · {{ $change->changedBy->name }}@endif</span>
                </li>
            @endforeach
        </ul>
    </section>
@endsection
