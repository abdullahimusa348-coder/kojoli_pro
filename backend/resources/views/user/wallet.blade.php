@extends('layouts.app')

@section('title', 'Wallet · Nadabo Global Data')

@section('page')
    <section class="flex flex-wrap items-end justify-between gap-3" aria-labelledby="wallet-heading">
        <div>
            <h1 id="wallet-heading" class="text-2xl font-semibold text-navy-900 sm:text-3xl">Wallet</h1>
            <p class="mt-1 text-sm text-navy-600">Your main wallet balance and history.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('wallet.fund') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-800 ring-1 ring-navy-200 hover:bg-navy-50" data-payment-history-link>Payment history</a>
            @if ($canFund)
                <a href="{{ route('wallet.fund') }}" class="inline-flex items-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700" data-fund-wallet>Fund wallet</a>
            @endif
        </div>
    </section>

    <section class="mt-6 grid gap-4 rounded-2xl bg-navy-900 p-5 text-white shadow-sm sm:grid-cols-2 sm:p-6" aria-label="Balances" data-wallet-balances>
        <div class="min-w-0">
            <p class="text-sm font-medium text-navy-100">Current balance</p>
            <p class="mt-1 break-all text-3xl font-semibold tabular-nums" data-wallet-balance>{{ \App\Support\Money::format($balanceKobo) }}</p>
        </div>
        <div class="min-w-0">
            <p class="text-sm font-medium text-navy-100">Available balance</p>
            <p class="mt-1 break-all text-3xl font-semibold tabular-nums" data-wallet-available>{{ \App\Support\Money::format($wallet?->availableKobo() ?? 0) }}</p>
        </div>
        @if ($wallet?->isFrozen())
            <p class="rounded-lg bg-white/10 px-3 py-2 text-sm sm:col-span-2" data-wallet-frozen>This wallet is frozen. Please contact support.</p>
        @endif
    </section>

    <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="history-heading" data-wallet-history>
        <h2 id="history-heading" class="border-b border-navy-100 px-5 py-3 text-base font-semibold text-navy-900">Wallet history</h2>
        @if (! $entries || $entries->isEmpty())
            <p class="px-5 py-6 text-sm text-navy-500">No wallet activity yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($entries as $entry)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm" data-ledger-entry="{{ $entry->reference }}">
                        <div class="min-w-0">
                            <p class="break-words font-medium text-navy-900">{{ $entry->description }}</p>
                            <p class="break-all text-xs text-navy-500"><time datetime="{{ $entry->created_at?->toIso8601String() }}">{{ $entry->created_at?->format('j M Y, H:i') }}</time> · <span class="font-mono">{{ $entry->reference }}</span></p>
                        </div>
                        <div class="text-right">
                            @include('partials.wallet.amount', ['direction' => $entry->direction, 'kobo' => $entry->amount_kobo])
                            <p class="text-xs tabular-nums text-navy-500">Balance {{ \App\Support\Money::format($entry->balance_after_kobo) }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
            @include('admin.services.partials.pagination', ['paginator' => $entries])
        @endif
    </section>

    <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="transactions-heading" data-wallet-transactions>
        <h2 id="transactions-heading" class="border-b border-navy-100 px-5 py-3 text-base font-semibold text-navy-900">Transactions</h2>
        @if ($transactions->isEmpty())
            <p class="px-5 py-6 text-sm text-navy-500">No transactions yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($transactions as $transaction)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm" data-transaction="{{ $transaction->reference }}">
                        <div class="min-w-0">
                            <p class="break-words font-medium text-navy-900">{{ $transaction->description }}</p>
                            <p class="break-all text-xs text-navy-500">{{ $transaction->created_at?->format('j M Y, H:i') }} · <span class="font-mono">{{ $transaction->reference }}</span></p>
                        </div>
                        <div class="flex items-center gap-2">
                            @include('partials.wallet.status-badge', ['status' => $transaction->status])
                            @include('partials.wallet.amount', ['direction' => $transaction->direction, 'kobo' => $transaction->amount_kobo])
                        </div>
                    </li>
                @endforeach
            </ul>
            @include('admin.services.partials.pagination', ['paginator' => $transactions])
        @endif
    </section>
@endsection
