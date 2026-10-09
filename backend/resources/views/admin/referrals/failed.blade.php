@extends('layouts.admin')

@section('title', 'Failed attempts · Referral & Commission · Admin · '.config('app.name'))
@section('heading', 'Referral & Commission')

@section('page')
    @include('admin.referrals.tabs')
    <p class="mt-3 max-w-3xl text-sm text-navy-600">Commissions that could not be credited. The purchase stayed successful, no commission was made and nothing was credited. Records are append-only: nothing here is retried or changed. Staff may compensate with an ordinary wallet adjustment.</p>

    <form method="GET" action="{{ route('admin.referrals.failed') }}" class="mt-6 flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100 sm:flex-row sm:items-end" role="search">
        <div class="min-w-0 flex-1">
            <label for="q" class="mb-1 block text-xs font-medium text-navy-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Purchase reference"
                   class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.referrals.failed') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Search</button>
        </div>
    </form>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($attempts->isEmpty())
            <div class="px-5 py-12 text-center" data-failed-empty>
                <p class="text-sm font-medium text-navy-800">{{ ($filters['q'] ?? null) ? 'No failed attempts found' : 'No failed commission attempts' }}</p>
                <p class="mt-1 text-sm text-navy-500">{{ ($filters['q'] ?? null) ? 'Try a different purchase reference.' : 'Failed attempts appear here when a commission cannot be credited.' }}</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($attempts as $attempt)
                    <li class="grid gap-3 px-5 py-4 text-sm md:grid-cols-[1.2fr_1fr_1.4fr_auto] md:items-center" data-failed-attempt>
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-navy-500">Purchase</p>
                            @if ($canViewPurchases)
                                <a href="{{ route('admin.purchases.show', $attempt->purchase) }}" class="block break-all font-mono text-xs font-semibold text-brand-700 hover:underline" data-failed-purchase>{{ $attempt->purchase->reference }}</a>
                            @else
                                <p class="break-all font-mono text-xs font-semibold text-navy-900" data-failed-purchase>{{ $attempt->purchase->reference }}</p>
                            @endif
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-navy-500">Referrer</p>
                            <p class="font-semibold text-navy-900" data-failed-referrer>Customer #{{ $attempt->referrer_id }}</p>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-navy-500">Reason code</p>
                            <p class="text-navy-900">{{ $attempt->reason_code->label() }}</p>
                            <p class="font-mono text-xs text-navy-600" data-failed-reason>{{ $attempt->reason_code->value }}</p>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-navy-500">Time</p>
                            <p class="text-navy-900" data-failed-time>{{ $attempt->created_at?->copy()->setTimezone($zone)->format('j M Y, H:i') }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    @include('admin.services.partials.pagination', ['paginator' => $attempts])
@endsection
