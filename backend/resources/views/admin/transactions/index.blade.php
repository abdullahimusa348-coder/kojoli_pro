@extends('layouts.admin')

@section('title', 'Transactions · Admin · '.config('app.name'))
@section('heading', 'Transactions')

@section('page')
    <p class="max-w-2xl text-sm text-navy-600">Customer transactions. Phase 8 records manual wallet adjustments; deposits and service purchases arrive in later phases.</p>

    @php($control = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
    <form method="GET" action="{{ route('admin.transactions') }}" class="mt-6 grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100 sm:grid-cols-2 lg:grid-cols-6" role="search">
        <div class="sm:col-span-2 lg:col-span-6 xl:col-span-2">
            <label for="q" class="mb-1 block text-xs font-medium text-navy-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, customer name or email" class="{{ $control }}">
        </div>
        @foreach (['type' => ['Type', $types, 'All types'], 'status' => ['Status', $statuses, 'All statuses'], 'direction' => ['Direction', $directions, 'Both']] as $name => [$label, $options, $all])
            <div class="lg:col-span-2 xl:col-span-1">
                <label for="{{ $name }}" class="mb-1 block text-xs font-medium text-navy-700">{{ $label }}</label>
                <select id="{{ $name }}" name="{{ $name }}" class="{{ $control }}">
                    <option value="">{{ $all }}</option>
                    @foreach ($options as $option)
                        <option value="{{ $option->value }}" @selected(($filters[$name] ?? '') === $option->value)>{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach
        <div class="lg:col-span-3 xl:col-span-1">
            <label for="from" class="mb-1 block text-xs font-medium text-navy-700">From</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="{{ $control }}">
        </div>
        <div class="lg:col-span-3 xl:col-span-1">
            <label for="to" class="mb-1 block text-xs font-medium text-navy-700">To</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="{{ $control }}">
        </div>
        @if ($errors->any())<p class="text-sm text-red-600 sm:col-span-2 lg:col-span-6">{{ $errors->first() }}</p>@endif
        <div class="flex gap-2 sm:col-span-2 sm:justify-end lg:col-span-6">
            <a href="{{ route('admin.transactions') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Apply</button>
        </div>
    </form>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($transactions->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-navy-800">No transactions found</p>
                <p class="mt-1 text-sm text-navy-500">{{ array_filter($filters) ? 'Try a different search or filter.' : 'Customer transactions appear here as they happen.' }}</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($transactions as $transaction)
                    <li class="flex flex-col gap-2 px-5 py-4 md:flex-row md:items-center" data-transaction="{{ $transaction->reference }}">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('admin.transactions.show', $transaction) }}" class="block break-all font-mono text-xs font-semibold text-brand-700 hover:underline">{{ $transaction->reference }}</a>
                            <p class="break-words text-sm text-navy-800">{{ $transaction->user->name }} · {{ $transaction->type->label() }} · {{ $transaction->direction->label() }}</p>
                            <p class="break-all text-xs text-navy-500">{{ $transaction->user->email }} · {{ $transaction->created_at?->format('j M Y, H:i') }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            @include('partials.wallet.status-badge', ['status' => $transaction->status])
                            @include('partials.wallet.amount', ['direction' => $transaction->direction, 'kobo' => $transaction->amount_kobo])
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @include('admin.services.partials.pagination', ['paginator' => $transactions])
@endsection
