@extends('layouts.app')

@section('title', 'My purchases · Nadabo Global Data')

@section('page')
    <section aria-labelledby="purchases-heading">
        <h1 id="purchases-heading" class="text-2xl font-semibold text-navy-900 sm:text-3xl">My purchases</h1>
    </section>

    <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" data-purchase-history>
        @if ($purchases->isEmpty())
            <p class="px-5 py-6 text-sm text-navy-500">No purchases yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($purchases as $purchase)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm" data-purchase="{{ $purchase->reference }}">
                        <div class="min-w-0">
                            <a href="{{ route('purchases.show', $purchase->reference) }}" class="block break-words font-medium text-navy-900 hover:text-brand-700">{{ $purchase->service_name }} · {{ $purchase->amount_type === \App\Support\Catalog\AmountType::Variable ? \App\Support\Money::format($purchase->face_value_kobo) : $purchase->plan_name }}</a>
                            <p class="break-all text-xs text-navy-500">{{ $purchase->network?->label() }} · <span class="tabular-nums">{{ $purchase->recipient }}</span> · {{ $purchase->created_at?->format('j M Y, H:i') }} · <span class="font-mono">{{ $purchase->reference }}</span></p>
                        </div>
                        <div class="flex items-center gap-2">
                            @include('partials.purchases.customer-status-badge', ['status' => $purchase->status])
                            <span class="font-semibold tabular-nums text-navy-900">{{ \App\Support\Money::format($purchase->amount_kobo) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
            @include('admin.services.partials.pagination', ['paginator' => $purchases])
        @endif
    </section>
@endsection
