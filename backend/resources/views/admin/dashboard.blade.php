@extends('layouts.admin')

@section('title', 'Admin dashboard · '.config('app.name'))
@section('heading', 'Dashboard')

@section('page')
    <p class="text-sm text-navy-700">Welcome back, {{ $staff->name }}.</p>

    @if ($cards !== [])
        <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5" aria-label="Key figures">
            @foreach ($cards as $card)
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100" data-card="{{ $card['key'] }}">
                    <p class="text-sm font-medium text-navy-600">{{ $card['label'] }}</p>
                    <p @class(['mt-2 text-2xl font-semibold tabular-nums', 'text-navy-900' => $card['live'], 'text-navy-400' => ! $card['live']])>{{ $card['value'] }}</p>
                    <p class="mt-2 text-xs text-navy-500">
                        @unless ($card['live'])
                            <span class="mr-1 inline-block rounded bg-navy-100 px-1.5 py-0.5 font-semibold uppercase tracking-wide text-navy-600">Not live</span>
                        @endunless
                        {{ $card['note'] }}
                    </p>
                </div>
            @endforeach
        </section>
    @endif

    @if ($showRecentTransactions)
        <section class="mt-6 rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="recent-transactions" data-panel="recent-transactions">
            <div class="border-b border-navy-100 px-5 py-4">
                <h2 id="recent-transactions" class="text-base font-semibold text-navy-900">Recent Transactions</h2>
            </div>
            @if ($recentTransactions === [])
                <div class="px-5 py-12 text-center">
                    <p class="text-sm font-medium text-navy-800">No transactions yet</p>
                    <p class="mt-1 text-sm text-navy-500">Customer transactions appear here as they happen.</p>
                </div>
            @else
                <ul class="divide-y divide-navy-100" role="list">
                    @foreach ($recentTransactions as $transaction)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm" data-recent-transaction="{{ $transaction->reference }}">
                            <div class="min-w-0">
                                <a href="{{ route('admin.transactions.show', $transaction) }}" class="break-all font-mono text-xs text-brand-700 hover:underline">{{ $transaction->reference }}</a>
                                <p class="break-words text-navy-700">{{ $transaction->user->name }} · {{ $transaction->type->label() }} · {{ $transaction->status->label() }}</p>
                            </div>
                            <span @class(['font-semibold tabular-nums', 'text-green-700' => $transaction->direction === \App\Support\Wallet\Direction::Credit, 'text-navy-900' => $transaction->direction === \App\Support\Wallet\Direction::Debit])>
                                {{ $transaction->direction === \App\Support\Wallet\Direction::Credit ? '+' : '−' }}{{ \App\Support\Money::format($transaction->amount_kobo) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    @if ($cards === [] && ! $showRecentTransactions)
        <div class="mt-6 rounded-2xl bg-white px-5 py-12 text-center shadow-sm ring-1 ring-navy-100">
            <p class="text-sm text-navy-700">There is nothing on the dashboard for your role yet.</p>
        </div>
    @endif
@endsection
