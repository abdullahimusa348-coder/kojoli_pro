@extends('layouts.app')

@section('title', 'Referrals · Nadabo Global Data')

@php($money = fn (int $kobo) => \App\Support\Money::format($kobo))
@section('page')
    <section aria-labelledby="referrals-heading">
        <h1 id="referrals-heading" class="text-2xl font-semibold text-navy-900 sm:text-3xl">Referrals</h1>
        <p class="mt-1 text-sm text-navy-600">Share your code or link. Anyone who creates an account with it becomes your referred customer, permanently.</p>
    </section>

    <section class="mt-6 rounded-2xl bg-navy-900 p-5 text-white shadow-sm sm:p-6" aria-label="Your referral code and link" data-referral-share
             x-data="{ copied: '', canShare: !! navigator.share,
                       copy(text, what) {
                           if (! navigator.clipboard) { this.$refs.link.select(); return; }
                           navigator.clipboard.writeText(text).then(() => { this.copied = what; setTimeout(() => this.copied = '', 2000); });
                       } }">
        <p class="text-sm font-medium text-navy-100">Your referral code</p>
        <div class="mt-1 flex flex-wrap items-center gap-3">
            <p class="break-all font-mono text-3xl font-semibold tracking-widest" data-referral-code>{{ $code }}</p>
            <button type="button" @click="copy(@js($code), 'code')" data-copy-code
                    class="inline-flex items-center rounded-lg bg-white/10 px-3 py-1.5 text-sm font-medium text-white hover:bg-white/20">Copy code</button>
        </div>

        <label for="referral-link" class="mt-5 block text-sm font-medium text-navy-100">Your referral link</label>
        <div class="mt-1 flex flex-col gap-2 sm:flex-row">
            <input id="referral-link" type="text" readonly value="{{ $link }}" x-ref="link" @focus="$event.target.select()" data-referral-link
                   class="block w-full min-w-0 rounded-lg border-0 bg-white/10 px-3 py-2 font-mono text-sm text-white focus:outline-none focus:ring-2 focus:ring-brand-300">
            <div class="flex shrink-0 gap-2">
                <button type="button" @click="copy(@js($link), 'link')" data-copy-link
                        class="inline-flex flex-1 items-center justify-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Copy link</button>
                <button type="button" x-show="canShare" x-cloak data-share-link
                        @click="navigator.share({ title: 'Nadabo Global Data', text: @js('Create your Nadabo Global Data account with my referral code '.$code.'.'), url: @js($link) }).catch(() => {})"
                        class="inline-flex flex-1 items-center justify-center rounded-lg bg-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/20">Share</button>
            </div>
        </div>
        <p class="mt-2 min-h-5 text-sm text-navy-100" role="status" aria-live="polite" x-text="copied === 'code' ? 'Code copied.' : (copied === 'link' ? 'Link copied.' : '')"></p>
    </section>

    <section class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Referral figures" data-referral-figures>
        @foreach ([
            'referred' => ['Referred customers', number_format($figures['referred'])],
            'successful' => ['Successful referrals', number_format($figures['successful'])],
            'earned' => ['Total earned', $money($figures['earned_kobo'])],
            'this-month' => ['Earned this month', $money($figures['this_month_kobo'])],
        ] as $key => [$label, $value])
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100" data-referral-figure="{{ $key }}">
                <p class="text-sm font-medium text-navy-600">{{ $label }}</p>
                <p class="mt-1 break-all text-2xl font-semibold tabular-nums text-navy-900">{{ $value }}</p>
            </div>
        @endforeach
    </section>
    <p class="mt-3 text-xs text-navy-500">A successful referral is a referred customer with at least one successful Data, Airtime, NIN, BVN or Exam PIN purchase. Earnings count credited referral commissions only.</p>

    <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="history-heading" data-referral-history>
        <h2 id="history-heading" class="border-b border-navy-100 px-5 py-3 text-base font-semibold text-navy-900">Referral history</h2>
        @if ($history->isEmpty())
            <p class="px-5 py-6 text-sm text-navy-500">No referred customers yet. Customers you refer appear here once they create their account.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($history as $referred)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm" data-referred-customer>
                        <p class="text-navy-900">Joined {{ $referred['joined'] }}</p>
                        <span @class(['rounded-full px-2.5 py-1 text-xs font-medium', 'bg-green-50 text-green-800' => $referred['status'] === 'Active', 'bg-navy-100 text-navy-700' => $referred['status'] !== 'Active'])>{{ $referred['status'] }}</span>
                    </li>
                @endforeach
            </ul>
            @include('admin.services.partials.pagination', ['paginator' => $history])
        @endif
    </section>

    <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="commissions-heading" data-commission-history>
        <h2 id="commissions-heading" class="border-b border-navy-100 px-5 py-3 text-base font-semibold text-navy-900">Commission history</h2>
        <p class="border-b border-navy-100 px-5 py-2 text-xs text-navy-500">Each line shows the amount originally credited. A reversed or cancelled commission stays listed, with its status.</p>
        @if ($commissions->isEmpty())
            <p class="px-5 py-6 text-sm text-navy-500" data-commission-empty>No commissions yet. A commission appears here when a customer you referred makes a qualifying purchase.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($commissions as $commission)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm" data-commission-row>
                        <div class="min-w-0">
                            <p class="text-navy-900" data-commission-date>{{ $commission['date'] }}</p>
                            <p class="font-semibold tabular-nums text-navy-900" data-commission-amount>{{ $money($commission['amount_kobo']) }}</p>
                        </div>
                        <span @class(['rounded-full px-2.5 py-1 text-xs font-medium', 'bg-green-50 text-green-800' => $commission['status_key'] === 'credited', 'bg-navy-100 text-navy-700' => $commission['status_key'] !== 'credited'])
                              data-commission-status="{{ $commission['status_key'] }}">{{ $commission['status'] }}</span>
                    </li>
                @endforeach
            </ul>
            @include('admin.services.partials.pagination', ['paginator' => $commissions])
        @endif
    </section>
@endsection
