@extends('layouts.admin')

@section('title', 'Commissions · Referral & Commission · Admin · '.config('app.name'))
@section('heading', 'Referral & Commission')

@section('page')
    @include('admin.referrals.tabs')
    <p class="mt-3 max-w-3xl text-sm text-navy-600">Referral commissions credited to referrers for their referred customers' successful purchases of the qualifying services. Open a commission to see its details, or to reverse or cancel it.</p>

    <form method="GET" action="{{ route('admin.referrals') }}" class="mt-6 flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100 sm:flex-row sm:items-end" role="search">
        <div class="min-w-0 flex-1">
            <label for="q" class="mb-1 block text-xs font-medium text-navy-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="{{ $canViewCustomers ? 'Commission or purchase reference, referrer name or email' : 'Commission or purchase reference' }}"
                   class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.referrals') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Search</button>
        </div>
    </form>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($commissions->isEmpty())
            <div class="px-5 py-12 text-center" data-commissions-empty>
                <p class="text-sm font-medium text-navy-800">{{ ($filters['q'] ?? null) ? 'No commissions found' : 'No commissions yet' }}</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-navy-500">{{ ($filters['q'] ?? null) ? 'Try a different search.' : 'Commissions appear here when referred customers\' purchases of the qualifying services succeed, once their rates and caps are set.' }}</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($commissions as $commission)
                    <li class="grid gap-3 px-5 py-4 text-sm md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_auto] md:items-center" data-commission="{{ $commission->reference }}">
                        <div class="min-w-0">
                            <a href="{{ route('admin.referrals.commissions.show', $commission) }}" class="block break-all font-mono text-sm font-semibold text-navy-900 hover:text-brand-700">{{ $commission->reference }}</a>
                            <p class="mt-0.5 break-all text-xs text-navy-600">{{ $commission->purchase->service_name }} · <span class="font-mono">{{ $commission->purchase->reference }}</span></p>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-navy-500">Referrer</p>
                            @if ($canViewCustomers)
                                <p class="truncate font-semibold text-navy-900">{{ $commission->referrer->name }}</p>
                                <p class="truncate text-navy-600">{{ $commission->referrer->email }}</p>
                            @else
                                <p class="truncate font-semibold text-navy-900">Customer #{{ $commission->referrer->id }}</p>
                            @endif
                        </div>
                        <div class="flex items-center justify-between gap-3 md:block md:text-right">
                            <p class="font-semibold tabular-nums text-navy-900" data-commission-amount>{{ \App\Support\Money::format($commission->amount_kobo) }}</p>
                            <p class="text-xs text-navy-500 md:mt-0.5">{{ $commission->credited_at?->format('j M Y, H:i') }}</p>
                            <div class="md:mt-1">@include('admin.referrals.partials.status', ['status' => $commission->status()])</div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    @include('admin.services.partials.pagination', ['paginator' => $commissions])
@endsection
