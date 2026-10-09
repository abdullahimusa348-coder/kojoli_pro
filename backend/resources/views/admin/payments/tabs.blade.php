{{-- Payments area tabs: Payments | Gateways. --}}
@php($paymentsTab = request()->routeIs('admin.payments.gateways*') ? 'gateways' : 'payments')
<div class="flex flex-col gap-3 border-b border-navy-100 sm:flex-row sm:items-end sm:justify-between">
    <nav class="-mb-px flex gap-x-5 overflow-x-auto" aria-label="Payments sections">
        @foreach (['payments' => ['Payments', route('admin.payments')], 'gateways' => ['Gateways', route('admin.payments.gateways')]] as $key => [$label, $url])
            <a href="{{ $url }}" data-payments-tab="{{ $key }}"
               @class(['shrink-0 border-b-2 px-1 pb-3 text-sm font-semibold', 'border-brand-600 text-brand-700' => $paymentsTab === $key, 'border-transparent text-navy-600 hover:text-navy-900' => $paymentsTab !== $key])
               @if ($paymentsTab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    @if ($paymentsTab === 'gateways')
        @can(\App\Support\Enums\SystemPermission::PaymentsGateways->value)
            <a href="{{ route('admin.payments.gateways.create') }}" class="mb-3 inline-flex items-center justify-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Add gateway</a>
        @endcan
    @endif
</div>

@if (session('status'))
    <x-alert class="mt-4">{{ session('status') }}</x-alert>
@endif
@if ($errors->any())
    <x-alert type="error" class="mt-4">{{ $errors->first() }}</x-alert>
@endif
