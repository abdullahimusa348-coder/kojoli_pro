@extends('layouts.admin')

@section('title', 'Wallet · '.$customer->name.' · Admin · '.config('app.name'))
@section('heading', 'Customer wallet')

@php($staff = auth('admin')->user())
@php($canAdjust = $staff->can(\App\Support\Enums\SystemPermission::WalletAdjust->value))
@php($canManage = $staff->can(\App\Support\Enums\SystemPermission::WalletManage->value))
@php($control = 'block w-full rounded-lg border px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
@php($frozen = $wallet?->isFrozen() ?? false)

@section('page')
    <a href="{{ route('admin.wallet') }}" class="text-sm text-brand-700 hover:underline">← Back to Wallets</a>
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif
    @error('reversal')<x-alert type="error" class="mt-4">{{ $message }}</x-alert>@enderror

    <div class="mt-4 grid gap-6 lg:grid-cols-3">
        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6 lg:col-span-2" data-wallet-summary>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="break-words text-lg font-semibold text-navy-900">{{ $customer->name }}</h2>
                    <p class="break-all text-sm text-navy-600">{{ $customer->email }} · {{ $customer->user_type->label() }}</p>
                </div>
                <span @class(['inline-flex rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-green-50 text-green-800' => ! $frozen, 'bg-red-50 text-red-800' => $frozen]) data-wallet-status>{{ $frozen ? 'Frozen' : 'Active' }}</span>
            </div>
            <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-navy-600">Current balance</dt><dd class="mt-0.5 break-all text-2xl font-semibold tabular-nums text-navy-900" data-wallet-balance>{{ \App\Support\Money::format($wallet?->balance_kobo ?? 0) }}</dd></div>
                <div><dt class="text-navy-600">Available balance</dt><dd class="mt-0.5 break-all text-2xl font-semibold tabular-nums text-navy-900">{{ \App\Support\Money::format($wallet?->availableKobo() ?? 0) }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-navy-500">{{ $wallet ? 'Main wallet · NGN' : 'No wallet activity yet. The wallet is created with its first entry.' }}@if ($frozen) · Debits are blocked while frozen; credits are allowed.@endif</p>
            <p class="mt-2"><a href="{{ route('admin.users.show', $customer) }}" class="text-sm text-brand-700 hover:underline">Customer profile</a></p>
        </section>

        <div class="space-y-6">
            @if ($canManage)
                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100" data-wallet-freeze>
                    <h2 class="text-base font-semibold text-navy-900">Wallet status</h2>
                    <form method="POST" action="{{ route('admin.wallet.status', $customer) }}" class="mt-3">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="{{ $frozen ? 'active' : 'frozen' }}">
                        <p class="text-sm text-navy-600">{{ $frozen ? 'Unfreezing allows debits again.' : 'Freezing blocks debits; credits are still allowed.' }}</p>
                        <button type="submit" class="mt-3 w-full rounded-lg px-4 py-2 text-sm font-semibold ring-1 {{ $frozen ? 'text-green-800 ring-green-200 hover:bg-green-50' : 'text-red-700 ring-red-200 hover:bg-red-50' }}" data-wallet-toggle="{{ $frozen ? 'unfreeze' : 'freeze' }}">{{ $frozen ? 'Unfreeze wallet' : 'Freeze wallet' }}</button>
                    </form>
                </section>
            @endif

            @if ($canAdjust)
                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100" aria-labelledby="adjust-heading" data-wallet-adjust>
                    <h2 id="adjust-heading" class="text-base font-semibold text-navy-900">Manual adjustment</h2>
                    <form method="POST" action="{{ route('admin.wallet.adjust', $customer) }}" class="mt-3 space-y-3" novalidate>
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $token) }}">
                        <fieldset class="flex gap-4 text-sm">
                            <legend class="sr-only">Direction</legend>
                            @foreach (\App\Support\Wallet\Direction::cases() as $direction)
                                <label class="flex items-center gap-2"><input type="radio" name="direction" value="{{ $direction->value }}" @checked(old('direction', 'credit') === $direction->value) class="border-navy-300 text-brand-600 focus:ring-brand-500"> {{ $direction->label() }}</label>
                            @endforeach
                        </fieldset>
                        <div>
                            <label for="amount" class="mb-1 block text-xs font-medium text-navy-700">Amount (₦)</label>
                            <input id="amount" name="amount" inputmode="decimal" autocomplete="off" value="{{ old('amount') }}" placeholder="e.g. 1,000.00" @class([$control, 'border-red-400' => $errors->has('amount'), 'border-navy-200' => ! $errors->has('amount')])>
                            @error('amount')<p id="amount-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="reason" class="mb-1 block text-xs font-medium text-navy-700">Reason (internal, at least 10 characters)</label>
                            <textarea id="reason" name="reason" rows="2" @class([$control, 'border-red-400' => $errors->has('reason'), 'border-navy-200' => ! $errors->has('reason')])>{{ old('reason') }}</textarea>
                            @error('reason')<p id="reason-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <label class="flex items-start gap-2 text-sm text-navy-800">
                            <input type="checkbox" name="confirm" value="1" class="mt-0.5 rounded border-navy-300 text-brand-600 focus:ring-brand-500">
                            <span>I confirm this adjustment. It is posted to the customer's wallet immediately and recorded permanently.</span>
                        </label>
                        @error('confirm')<p id="confirm-error" class="text-sm text-red-600">{{ $message }}</p>@enderror
                        @error('idempotency_key')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                        <x-button>Post adjustment</x-button>
                    </form>
                </section>
            @endif
        </div>
    </div>

    <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="tx-heading" data-wallet-transactions>
        <h2 id="tx-heading" class="border-b border-navy-100 px-5 py-3 text-base font-semibold text-navy-900">Transactions</h2>
        @if ($transactions->isEmpty())
            <p class="px-5 py-6 text-sm text-navy-500">No transactions yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($transactions as $transaction)
                    <li class="space-y-2 px-5 py-3 text-sm" data-transaction="{{ $transaction->reference }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                @can(\App\Support\Enums\SystemPermission::TransactionsView->value)
                                    <a href="{{ route('admin.transactions.show', $transaction) }}" class="break-all font-mono text-xs text-brand-700 hover:underline">{{ $transaction->reference }}</a>
                                @else
                                    <span class="break-all font-mono text-xs text-navy-700">{{ $transaction->reference }}</span>
                                @endcan
                                <p class="break-words text-navy-800">{{ $transaction->description }}</p>
                                <p class="break-words text-xs text-navy-500">{{ $transaction->created_at?->format('j M Y, H:i') }}@if ($transaction->createdBy) · by {{ $transaction->createdBy->name }}@endif @if ($transaction->internalReason()) · Note: {{ $transaction->internalReason() }}@endif</p>
                            </div>
                            <div class="flex items-center gap-2">
                                @include('partials.wallet.status-badge', ['status' => $transaction->status])
                                @include('partials.wallet.amount', ['direction' => $transaction->direction, 'kobo' => $transaction->amount_kobo])
                            </div>
                        </div>
                        @if ($canAdjust && $transaction->status === \App\Support\Wallet\TransactionStatus::Successful && $transaction->type === \App\Support\Wallet\TransactionType::Adjustment)
                            <details class="rounded-lg bg-navy-50/60 p-3" data-reverse="{{ $transaction->reference }}">
                                <summary class="cursor-pointer text-sm font-medium text-brand-700">Reverse this adjustment</summary>
                                <form method="POST" action="{{ route('admin.wallet.reverse', [$customer, $transaction]) }}" class="mt-3 space-y-2" novalidate>
                                    @csrf
                                    <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <label for="reversal-reason-{{ $transaction->id }}" class="block text-xs font-medium text-navy-700">Reason (internal, at least 10 characters)</label>
                                    <textarea id="reversal-reason-{{ $transaction->id }}" name="reversal_reason" rows="2" class="{{ $control }} border-navy-200"></textarea>
                                    <label class="flex items-start gap-2 text-sm text-navy-800">
                                        <input type="checkbox" name="reversal_confirm" value="1" class="mt-0.5 rounded border-navy-300 text-brand-600 focus:ring-brand-500">
                                        <span>Post a compensating {{ $transaction->direction->opposite()->value }} of {{ \App\Support\Money::format($transaction->amount_kobo) }}. This cannot be undone.</span>
                                    </label>
                                    @if ($errors->has('reversal_reason') || $errors->has('reversal_confirm'))
                                        <p class="text-sm text-red-600">{{ $errors->first('reversal_reason') ?: $errors->first('reversal_confirm') }}</p>
                                    @endif
                                    <button type="submit" class="rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Reverse</button>
                                </form>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @include('admin.services.partials.pagination', ['paginator' => $transactions])

    <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="ledger-heading" data-wallet-ledger>
        <h2 id="ledger-heading" class="border-b border-navy-100 px-5 py-3 text-base font-semibold text-navy-900">Ledger</h2>
        @if (! $entries || $entries->isEmpty())
            <p class="px-5 py-6 text-sm text-navy-500">No ledger entries yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($entries as $entry)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm" data-ledger-entry="{{ $entry->reference }}">
                        <div class="min-w-0">
                            <p class="break-words text-navy-800">{{ $entry->description }} <span class="text-xs text-navy-500">· {{ $entry->entry_type->label() }}</span></p>
                            <p class="break-all text-xs text-navy-500"><span class="font-mono">{{ $entry->reference }}</span> · {{ $entry->created_at?->format('j M Y, H:i') }}@if ($entry->createdBy) · by {{ $entry->createdBy->name }}@endif</p>
                        </div>
                        <div class="text-right">
                            @include('partials.wallet.amount', ['direction' => $entry->direction, 'kobo' => $entry->amount_kobo])
                            <p class="text-xs tabular-nums text-navy-500">Balance {{ \App\Support\Money::format($entry->balance_after_kobo) }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @if ($entries)
        @include('admin.services.partials.pagination', ['paginator' => $entries])
    @endif
@endsection
