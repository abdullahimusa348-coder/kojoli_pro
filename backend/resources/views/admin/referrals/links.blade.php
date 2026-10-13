@extends('layouts.admin')

@section('title', 'Referrals · Referral & Commission · Admin · '.config('app.name'))
@section('heading', 'Referral & Commission')

@section('page')
    @include('admin.referrals.tabs')
    <p class="mt-3 max-w-3xl text-sm text-navy-600">Each customer who signed up with a referral code, and the customer whose code they used. Links are made only at signup and never change.</p>

    <form method="GET" action="{{ route('admin.referrals.links') }}" class="mt-6 flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100 sm:flex-row sm:items-end" role="search">
        <div class="min-w-0 flex-1">
            <label for="q" class="mb-1 block text-xs font-medium text-navy-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Name, email or referral code"
                   class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.referrals.links') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Search</button>
        </div>
    </form>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($links->isEmpty())
            <div class="px-5 py-12 text-center" data-links-empty>
                <p class="text-sm font-medium text-navy-800">{{ ($filters['q'] ?? null) ? 'No referrals found' : 'No referrals yet' }}</p>
                <p class="mt-1 text-sm text-navy-500">{{ ($filters['q'] ?? null) ? 'Try a different search.' : 'Referrals appear here when customers sign up with a referral code.' }}</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($links as $link)
                    <li class="grid gap-3 px-5 py-4 text-sm md:grid-cols-[1fr_1fr_auto] md:items-center" data-referral-link="{{ $link->id }}">
                        @foreach (['referred' => 'Referred customer', 'referrer' => 'Referred by'] as $side => $title)
                            <div class="min-w-0">
                                <p class="text-xs font-medium text-navy-500">{{ $title }}</p>
                                @if ($canViewCustomers)
                                    <a href="{{ route('admin.users.show', $link->getAttribute($side === 'referred' ? 'referred_user_id' : 'referrer_id')) }}" class="block truncate font-semibold text-navy-900 hover:text-brand-700">{{ $link->getAttribute($side.'_name') }}</a>
                                @else
                                    <p class="truncate font-semibold text-navy-900">{{ $link->getAttribute($side.'_name') }}</p>
                                @endif
                                <p class="truncate text-navy-600">{{ $link->getAttribute($side.'_email') }}</p>
                                @if ($side === 'referrer' && $link->getAttribute('referrer_code'))
                                    <p class="text-xs text-navy-600">Code <span class="font-mono font-medium text-navy-800" data-referrer-code>{{ $link->getAttribute('referrer_code') }}</span></p>
                                @endif
                            </div>
                        @endforeach
                        <p class="text-xs text-navy-500 md:text-right">{{ $link->created_at?->format('j M Y, H:i') }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    @include('admin.services.partials.pagination', ['paginator' => $links])
@endsection
