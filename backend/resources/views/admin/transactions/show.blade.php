@extends('layouts.admin')

@section('title', $transaction->reference.' · Transactions · Admin · '.config('app.name'))
@section('heading', 'Transaction details')

@section('page')
    <a href="{{ route('admin.transactions') }}" class="text-sm text-brand-700 hover:underline">← Back to Transactions</a>

    <section class="mt-4 max-w-3xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-transaction-details>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h2 class="break-all font-mono text-base font-semibold text-navy-900">{{ $transaction->reference }}</h2>
            @include('partials.wallet.status-badge', ['status' => $transaction->status])
        </div>
        <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Customer</dt><dd class="mt-0.5 break-words font-medium text-navy-900">{{ $transaction->user->name }} <span class="block break-all text-xs font-normal text-navy-500">{{ $transaction->user->email }}</span></dd></div>
            <div><dt class="text-navy-600">Amount</dt><dd class="mt-0.5">@include('partials.wallet.amount', ['direction' => $transaction->direction, 'kobo' => $transaction->amount_kobo])</dd></div>
            <div><dt class="text-navy-600">Type</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $transaction->type->label() }}</dd></div>
            <div><dt class="text-navy-600">Wallet</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $transaction->wallet->type->label() }}</dd></div>
            <div><dt class="text-navy-600">Created</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $transaction->created_at?->format('j M Y, H:i:s') }}@if ($transaction->createdBy) <span class="block text-xs font-normal text-navy-500">by {{ $transaction->createdBy->name }}</span>@endif</dd></div>
            <div><dt class="text-navy-600">Completed</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $transaction->completed_at?->format('j M Y, H:i:s') ?? '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-navy-600">Description (shown to the customer)</dt><dd class="mt-0.5 break-words text-navy-900">{{ $transaction->description }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-navy-600">Internal note</dt><dd class="mt-0.5 break-words text-navy-900" data-internal-reason>{{ $transaction->internalReason() ?? '—' }}</dd></div>
        </dl>
        @can(\App\Support\Enums\SystemPermission::WalletView->value)
            <p class="mt-4"><a href="{{ route('admin.wallet.show', $transaction->user) }}" class="text-sm text-brand-700 hover:underline">Open customer wallet</a></p>
        @endcan
    </section>

    <section class="mt-6 max-w-3xl overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="entries-heading" data-transaction-entries>
        <h2 id="entries-heading" class="border-b border-navy-100 px-5 py-3 text-base font-semibold text-navy-900">Ledger entries</h2>
        <ul class="divide-y divide-navy-100" role="list">
            @foreach ($transaction->entries as $entry)
                <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm" data-ledger-entry="{{ $entry->reference }}">
                    <div class="min-w-0">
                        <p class="break-words text-navy-800">{{ $entry->description }} <span class="text-xs text-navy-500">· {{ $entry->entry_type->label() }}</span></p>
                        <p class="break-all text-xs text-navy-500"><span class="font-mono">{{ $entry->reference }}</span> · {{ $entry->created_at?->format('j M Y, H:i:s') }}@if ($entry->createdBy) · by {{ $entry->createdBy->name }}@endif @if ($entry->metadata['reason'] ?? null) · Note: {{ $entry->metadata['reason'] }}@endif</p>
                    </div>
                    <div class="text-right">
                        @include('partials.wallet.amount', ['direction' => $entry->direction, 'kobo' => $entry->amount_kobo])
                        <p class="text-xs tabular-nums text-navy-500">Balance {{ \App\Support\Money::format($entry->balance_after_kobo) }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@endsection
