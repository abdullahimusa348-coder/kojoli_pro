@extends('layouts.admin')

@section('title', 'Products · Admin · '.config('app.name'))
@section('heading', 'Services')

@section('page')
    @include('admin.services.partials.tabs')

    @php($control = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
    <form method="GET" action="{{ route('admin.services.products') }}" class="mt-6 grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100 sm:grid-cols-2 lg:grid-cols-5" role="search">
        <div class="lg:col-span-2">
            <label for="q" class="mb-1 block text-xs font-medium text-navy-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Name, code or description" class="{{ $control }}">
        </div>
        <div>
            <label for="service" class="mb-1 block text-xs font-medium text-navy-700">Service</label>
            <select id="service" name="service" class="{{ $control }}">
                <option value="">All services</option>
                @foreach ($services as $service)
                    <option value="{{ $service->id }}" @selected((string) ($filters['service'] ?? '') === (string) $service->id)>{{ $service->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="network" class="mb-1 block text-xs font-medium text-navy-700">Network</label>
            <select id="network" name="network" class="{{ $control }}">
                <option value="">All networks</option>
                @foreach ($networks as $network)
                    <option value="{{ $network->value }}" @selected(($filters['network'] ?? '') === $network->value)>{{ $network->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status" class="mb-1 block text-xs font-medium text-navy-700">Status</label>
            <select id="status" name="status" class="{{ $control }}">
                <option value="">All statuses</option>
                <option value="available" @selected(($filters['status'] ?? '') === 'available')>Available</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                <option value="disabled" @selected(($filters['status'] ?? '') === 'disabled')>Disabled</option>
            </select>
        </div>
        <div class="flex gap-2 sm:col-span-2 sm:justify-end lg:col-span-5">
            <a href="{{ route('admin.services.products') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Apply</button>
        </div>
    </form>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($products->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-navy-800">No products found</p>
                <p class="mt-1 text-sm text-navy-500">Try a different search or filter.</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($products as $product)
                    <li class="flex flex-col gap-3 px-5 py-4 md:flex-row md:items-center" data-product="{{ $product->code }}">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('admin.services.products.show', $product) }}" class="block break-words text-sm font-semibold text-navy-900 hover:text-brand-700">{{ $product->name }}</a>
                            <p class="break-words text-xs text-navy-500">
                                <span class="font-mono">{{ $product->code }}</span> · {{ $product->service->name }}
                                @if ($product->network) · {{ $product->network->label() }} @endif
                                · {{ $product->plans_count }} {{ \Illuminate\Support\Str::plural('plan', $product->plans_count) }}
                            </p>
                        </div>
                        <div class="md:w-52">@include('admin.services.partials.status', ['label' => $product->statusLabel()])</div>
                        <div class="flex flex-wrap gap-2 md:w-40 md:justify-end">
                            @can(\App\Support\Enums\SystemPermission::ServicesUpdate->value)
                                <a href="{{ route('admin.services.products.edit', $product) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">Edit</a>
                            @endcan
                            @include('admin.services.partials.toggle', ['action' => route('admin.services.products.status', $product), 'active' => $product->is_active])
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @include('admin.services.partials.pagination', ['paginator' => $products])
@endsection
