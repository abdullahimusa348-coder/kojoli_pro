@extends('layouts.admin')

@section('title', 'Wallets · Admin · '.config('app.name'))
@section('heading', 'Wallets')

@section('page')
    <p class="max-w-2xl text-sm text-navy-600">Customer main wallets. Balances come from the wallet ledger; customers without any activity have ₦0.00.</p>

    @php($control = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
    <form method="GET" action="{{ route('admin.wallet') }}" class="mt-6 grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100 sm:grid-cols-3" role="search">
        <div class="sm:col-span-2">
            <label for="q" class="mb-1 block text-xs font-medium text-navy-700">Search customers</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Name, email or phone" class="{{ $control }}">
        </div>
        <div>
            <label for="status" class="mb-1 block text-xs font-medium text-navy-700">Wallet</label>
            <select id="status" name="status" class="{{ $control }}">
                <option value="">All customers</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active wallets</option>
                <option value="frozen" @selected(($filters['status'] ?? '') === 'frozen')>Frozen wallets</option>
                <option value="none" @selected(($filters['status'] ?? '') === 'none')>No wallet activity yet</option>
            </select>
        </div>
        <div class="flex gap-2 sm:col-span-3 sm:justify-end">
            <a href="{{ route('admin.wallet') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Apply</button>
        </div>
    </form>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($customers->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-navy-800">No customers found</p>
                <p class="mt-1 text-sm text-navy-500">Try a different search or filter.</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($customers as $customer)
                    <li class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center" data-wallet-customer="{{ $customer->id }}">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('admin.wallet.show', $customer) }}" class="block break-words text-sm font-semibold text-navy-900 hover:text-brand-700">{{ $customer->name }}</a>
                            <p class="break-all text-xs text-navy-500">{{ $customer->email }} · {{ $customer->user_type->label() }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            @if ($customer->wallet_status)
                                <span @class(['inline-flex rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-green-50 text-green-800' => $customer->wallet_status === 'active', 'bg-red-50 text-red-800' => $customer->wallet_status === 'frozen']) data-wallet-status="{{ $customer->wallet_status }}">{{ ucfirst($customer->wallet_status) }}</span>
                            @endif
                            <span class="whitespace-nowrap text-sm font-semibold tabular-nums text-navy-900" data-wallet-balance>{{ \App\Support\Money::format((int) ($customer->wallet_balance_kobo ?? 0)) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @include('admin.services.partials.pagination', ['paginator' => $customers])
@endsection
