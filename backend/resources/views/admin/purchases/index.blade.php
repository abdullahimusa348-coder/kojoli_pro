@extends('layouts.admin')

@section('title', 'Purchases · Admin · '.config('app.name'))
@section('heading', 'Purchases')

@section('page')
    <p class="max-w-3xl text-sm text-navy-600">Customer purchases. A purchase is settled only by a definite provider outcome; unclear outcomes stay pending and are re-checked automatically, then move to review after 24 hours.</p>
    @if ($reviewCount > 0)
        <p class="mt-3 inline-flex rounded-lg bg-purple-50 px-3 py-2 text-sm font-medium text-purple-800" data-review-count>
            <a href="{{ route('admin.purchases', ['status' => 'review']) }}" class="hover:underline">{{ $reviewCount }} {{ Str::plural('purchase', $reviewCount) }} in review</a>
        </p>
    @endif

    @php($control = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
    <form method="GET" action="{{ route('admin.purchases') }}" class="mt-6 grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100 sm:grid-cols-2 lg:grid-cols-6" role="search" data-purchase-filters>
        <div class="sm:col-span-2 lg:col-span-6 xl:col-span-2">
            <label for="q" class="mb-1 block text-xs font-medium text-navy-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, phone number, customer" class="{{ $control }}">
        </div>
        @foreach ([
            'status' => ['Status', collect($statuses)->map(fn ($s) => [$s->value, $s->label()]), 'All statuses'],
            'service' => ['Service', $services->map(fn ($s) => [(string) $s->id, $s->name]), 'All services'],
            'network' => ['Network', collect($networks)->map(fn ($n) => [$n->value, $n->label()]), 'All networks'],
            'provider' => ['Provider', $providers->map(fn ($p) => [(string) $p->id, $p->name]), 'All providers'],
        ] as $name => [$label, $options, $all])
            <div class="lg:col-span-3 xl:col-span-1">
                <label for="{{ $name }}" class="mb-1 block text-xs font-medium text-navy-700">{{ $label }}</label>
                <select id="{{ $name }}" name="{{ $name }}" class="{{ $control }}">
                    <option value="">{{ $all }}</option>
                    @foreach ($options as [$value, $text])
                        <option value="{{ $value }}" @selected((string) ($filters[$name] ?? '') === (string) $value)>{{ $text }}</option>
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
            <a href="{{ route('admin.purchases') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Apply</button>
        </div>
    </form>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($purchases->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-navy-800">No purchases found</p>
                <p class="mt-1 text-sm text-navy-500">{{ array_filter($filters) ? 'Try a different search or filter.' : 'Customer purchases appear here once customers start buying.' }}</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($purchases as $purchase)
                    <li class="flex flex-col gap-2 px-5 py-4 md:flex-row md:items-center" data-purchase="{{ $purchase->reference }}">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('admin.purchases.show', $purchase) }}" class="block break-all font-mono text-xs font-semibold text-brand-700 hover:underline">{{ $purchase->reference }}</a>
                            <p class="break-words text-sm text-navy-800">{{ $purchase->service_name }} · {{ $purchase->product_name }} · {{ $purchase->plan_name }} → <span class="tabular-nums">{{ $purchase->recipient }}</span></p>
                            <p class="break-all text-xs text-navy-500">{{ $purchase->user->name }} ({{ $purchase->user->email }}) · {{ $purchase->created_at?->format('j M Y, H:i') }}@if ($purchase->successfulAttempt) · via {{ $purchase->successfulAttempt->provider->name }} (route {{ $purchase->successfulAttempt->route_priority }})@endif</p>
                        </div>
                        <div class="flex items-center gap-3">
                            @include('partials.purchases.status-badge', ['status' => $purchase->status])
                            <span class="font-semibold tabular-nums text-navy-900">{{ \App\Support\Money::format($purchase->amount_kobo) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @include('admin.services.partials.pagination', ['paginator' => $purchases])
@endsection
