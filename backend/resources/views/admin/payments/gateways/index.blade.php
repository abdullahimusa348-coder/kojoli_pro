@extends('layouts.admin')

@section('title', 'Gateways · Payments · Admin · '.config('app.name'))
@section('heading', 'Payments')

@php($canGateways = auth('admin')->user()->can(\App\Support\Enums\SystemPermission::PaymentsGateways->value))
@section('page')
    @include('admin.payments.tabs')
    <p class="mt-3 max-w-3xl text-sm text-navy-600">Payment gateways in priority order. Customers can fund their wallet only through gateways that are active and fully configured. There is no automatic failover between gateways.</p>
    <p class="mt-2 text-sm" data-live-switch>
        Live payments: <span @class(['font-semibold', 'text-red-700' => $liveEnabled, 'text-navy-800' => ! $liveEnabled])>{{ $liveEnabled ? 'switched on' : 'switched off' }}</span>
        <span class="text-navy-500">(Settings → Payments)</span>
    </p>
    @if ($drivers === [])
        <p class="mt-3 max-w-3xl rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800" data-no-drivers>No gateway drivers are installed yet. Gateway adapters are added in later steps, once each gateway's official documentation has been verified.</p>
    @endif

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($gateways->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-navy-800">No gateways yet</p>
                <p class="mt-1 text-sm text-navy-500">Gateways are added by an administrator with “Add gateway”.</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($gateways as $gateway)
                    <li class="flex flex-col gap-3 px-5 py-4 md:flex-row md:items-center" data-gateway="{{ $gateway->code }}">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('admin.payments.gateways.show', $gateway) }}" class="block break-words text-sm font-semibold text-navy-900 hover:text-brand-700">{{ $loop->iteration }}. {{ $gateway->name }}</a>
                            <p class="break-all text-xs text-navy-500">{{ $gateway->code }} · driver {{ $gateway->driver }} · {{ $gateway->payments_count }} {{ Str::plural('payment', $gateway->payments_count) }}{{ $gateway->wallet_funding ? '' : ' · wallet funding off' }}</p>
                            @if ($problems[$gateway->id])<p class="break-words text-xs text-amber-800" data-config-problem>{{ $problems[$gateway->id] }}</p>@endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @include('admin.payments.gateways.status', ['gateway' => $gateway, 'problem' => $problems[$gateway->id]])
                            @if ($canGateways)
                                @foreach (['up' => '↑', 'down' => '↓'] as $direction => $arrow)
                                    <form method="POST" action="{{ route('admin.payments.gateways.move', $gateway) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="direction" value="{{ $direction }}">
                                        <button type="submit" class="rounded-lg px-2.5 py-1 text-sm font-medium text-navy-800 ring-1 ring-navy-200 hover:bg-navy-50 disabled:opacity-40" aria-label="Move {{ $gateway->name }} {{ $direction }}" data-move="{{ $direction }}" @disabled(($direction === 'up' && $loop->first) || ($direction === 'down' && $loop->last))>{{ $arrow }}</button>
                                    </form>
                                @endforeach
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
