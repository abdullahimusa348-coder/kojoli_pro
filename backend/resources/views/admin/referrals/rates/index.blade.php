@extends('layouts.admin')

@section('title', 'Rates & caps · Referral & Commission · Admin · '.config('app.name'))
@section('heading', 'Referral & Commission')

@php($canManage = auth('admin')->user()->can(\App\Support\Enums\SystemPermission::ReferralsManage->value))
@php($values = fn (?int $rateBps, ?int $capKobo) => $rateBps === null ? 'Not set' : 'rate '.\App\Support\Pricing\BasisPoints::label($rateBps).', cap '.\App\Support\Money::format($capKobo))
@section('page')
    @include('admin.referrals.tabs')
    <p class="mt-3 max-w-3xl text-sm text-navy-600">Commission is a percentage of the purchase amount, limited to the cap for each purchase and rounded down to the kobo. Only the services below earn commission. A service with no rate saved, or a rate or cap of 0, earns none. A change applies to purchases that succeed after it is saved; commissions already credited are never recalculated.</p>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($services->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-navy-800">No qualifying services in the catalog</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($services as $service)
                    @php($setting = $settings->get($service->id))
                    <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center" data-commission-rate="{{ $service->slug }}">
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-sm font-semibold text-navy-900">{{ $service->name }}</p>
                            @if ($setting)
                                <p class="break-words text-sm text-navy-700">
                                    <span data-rate>Rate {{ \App\Support\Pricing\BasisPoints::label($setting->rate_bps) }}</span> ·
                                    <span data-cap>cap {{ \App\Support\Money::format($setting->cap_kobo) }} per purchase</span>
                                    @if ($setting->rate_bps === 0 || $setting->cap_kobo === 0)<span class="text-amber-800" data-no-commission>(no commission)</span>@endif
                                </p>
                                <p class="break-words text-xs text-navy-500">Last changed {{ $setting->updated_at?->format('j M Y, H:i') }} · {{ $setting->updatedBy?->name ?? '—' }}</p>
                            @else
                                <p class="text-sm text-navy-500" data-not-set>Not set (no commission)</p>
                            @endif
                        </div>
                        @if ($canManage)
                            <a href="{{ route('admin.referrals.rates.edit', $service) }}" data-edit-rate="{{ $service->slug }}"
                               class="inline-flex items-center justify-center rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">{{ $setting ? 'Edit' : 'Set rate' }}<span class="sr-only">&nbsp;for {{ $service->name }}</span></a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="history-heading" data-commission-history>
        <h2 id="history-heading" class="border-b border-navy-100 px-5 py-3 text-base font-semibold text-navy-900">Rate &amp; cap history</h2>
        @if ($history->isEmpty())
            <p class="px-5 py-6 text-sm text-navy-500">No changes yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($history as $change)
                    <li class="px-5 py-3 text-sm" data-commission-change="{{ $change->service->slug }}">
                        <p class="break-words text-navy-900"><span class="font-semibold">{{ $change->service->name }}:</span> {{ $values($change->old_rate_bps, $change->old_cap_kobo) }} → {{ $values($change->new_rate_bps, $change->new_cap_kobo) }}</p>
                        <p class="mt-0.5 whitespace-pre-line break-words text-navy-700" data-change-reason>{{ $change->reason }}</p>
                        <p class="mt-0.5 break-words text-xs text-navy-500">{{ $change->created_at?->format('j M Y, H:i') }} · {{ $change->changedBy?->name ?? '—' }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @include('admin.services.partials.pagination', ['paginator' => $history])
@endsection
