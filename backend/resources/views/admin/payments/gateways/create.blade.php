@extends('layouts.admin')

@section('title', 'Add gateway · Payments · Admin · '.config('app.name'))
@section('heading', 'Add gateway')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.payments.gateways') }}" class="text-sm text-brand-700 hover:underline">← Back to Gateways</a>
        @if ($drivers === [])
            <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800" data-no-drivers>No gateway drivers are installed yet, so a gateway cannot be added. Gateway adapters are added in later steps, once each gateway's official documentation has been verified.</p>
        @else
            <form method="POST" action="{{ route('admin.payments.gateways.store') }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate>
                @csrf
                <x-input name="name" label="Name" placeholder="Name shown to customers" autocomplete="off" required />
                <x-input name="code" label="Code" placeholder="e.g. main-gateway" autocomplete="off" required />
                <p class="-mt-2 mb-4 text-xs text-navy-600">Lowercase letters, numbers and dashes. Used in the webhook address; cannot be changed later.</p>
                <div class="mb-4">
                    <label for="driver" class="mb-1 block text-sm font-medium text-navy-800">Driver</label>
                    <select id="driver" name="driver" class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
                        @foreach ($drivers as $driver => $adapter)
                            <option value="{{ $driver }}" @selected(old('driver') === $driver)>{{ $adapter->label() }}</option>
                        @endforeach
                    </select>
                    @error('driver')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <input type="hidden" name="wallet_funding" value="0">
                <label class="mb-4 flex items-center gap-2 text-sm text-navy-800">
                    <input type="checkbox" name="wallet_funding" value="1" class="rounded border-navy-300 text-brand-600 focus:ring-brand-500" @checked(old('wallet_funding', '1') === '1')>
                    Use for wallet funding
                </label>
                <p class="mb-4 text-xs text-navy-600">New gateways start inactive and in sandbox mode. Endpoints are fixed by the driver; credentials are added on the gateway page.</p>
                <div class="flex justify-end"><x-button class="sm:w-auto">Create gateway</x-button></div>
            </form>
        @endif
    </div>
@endsection
