@extends('layouts.admin')

@section('title', 'Payments · Admin · '.config('app.name'))
@section('heading', 'Payments')

@section('page')
    @include('admin.payments.tabs')
    <p class="mt-3 max-w-3xl text-sm text-navy-600">Wallet-funding payments. A payment is credited to the wallet only after the gateway confirms it server-side with the exact amount. Payments that could not be credited automatically wait in review.</p>
    @if ($reviewCount > 0)
        <p class="mt-3 inline-flex rounded-lg bg-purple-50 px-3 py-2 text-sm font-medium text-purple-800" data-review-count>
            <a href="{{ route('admin.payments', ['status' => 'review']) }}" class="hover:underline">{{ $reviewCount }} {{ Str::plural('payment', $reviewCount) }} need review</a>
        </p>
    @endif

    @php($control = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
    <form method="GET" action="{{ route('admin.payments') }}" class="mt-6 grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100 sm:grid-cols-2 lg:grid-cols-6" role="search">
        <div class="sm:col-span-2 lg:col-span-6 xl:col-span-2">
            <label for="q" class="mb-1 block text-xs font-medium text-navy-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, gateway reference, customer" class="{{ $control }}">
        </div>
        <div class="lg:col-span-3 xl:col-span-1">
            <label for="status" class="mb-1 block text-xs font-medium text-navy-700">Status</label>
            <select id="status" name="status" class="{{ $control }}">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="lg:col-span-3 xl:col-span-1">
            <label for="gateway" class="mb-1 block text-xs font-medium text-navy-700">Gateway</label>
            <select id="gateway" name="gateway" class="{{ $control }}">
                <option value="">All gateways</option>
                @foreach ($gateways as $gateway)
                    <option value="{{ $gateway->id }}" @selected((string) ($filters['gateway'] ?? '') === (string) $gateway->id)>{{ $gateway->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="lg:col-span-3 xl:col-span-1">
            <label for="from" class="mb-1 block text-xs font-medium text-navy-700">From</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="{{ $control }}">
        </div>
        <div class="lg:col-span-3 xl:col-span-1">
            <label for="to" class="mb-1 block text-xs font-medium text-navy-700">To</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="{{ $control }}">
        </div>
        <div class="flex gap-2 sm:col-span-2 sm:justify-end lg:col-span-6">
            <a href="{{ route('admin.payments') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Apply</button>
        </div>
    </form>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($payments->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-navy-800">No payments found</p>
                <p class="mt-1 text-sm text-navy-500">{{ array_filter($filters) ? 'Try a different search or filter.' : 'Wallet-funding payments appear here once customers start them.' }}</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($payments as $payment)
                    <li class="flex flex-col gap-2 px-5 py-4 md:flex-row md:items-center" data-payment="{{ $payment->reference }}">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('admin.payments.show', $payment) }}" class="block break-all font-mono text-xs font-semibold text-brand-700 hover:underline">{{ $payment->reference }}</a>
                            <p class="break-words text-sm text-navy-800">{{ $payment->user->name }} · {{ $payment->gateway->name }} · {{ $payment->mode->label() }}</p>
                            <p class="break-all text-xs text-navy-500">{{ $payment->user->email }} · {{ $payment->created_at?->format('j M Y, H:i') }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            @include('partials.payments.status-badge', ['status' => $payment->status])
                            <span class="font-semibold tabular-nums text-navy-900">{{ \App\Support\Money::format($payment->amount_kobo) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @include('admin.services.partials.pagination', ['paginator' => $payments])
@endsection
