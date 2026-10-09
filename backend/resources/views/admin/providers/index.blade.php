@extends('layouts.admin')

@section('title', 'Providers · Admin · '.config('app.name'))
@section('heading', 'Providers')

@section('page')
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="max-w-2xl text-sm text-navy-600">External providers and the services they support. A provider is called for purchases only when an adapter is installed for its driver and one of its plan routes can run.</p>
        @can(\App\Support\Enums\SystemPermission::ProvidersCreate->value)
            <a href="{{ route('admin.providers.create') }}" class="inline-flex items-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Add provider</a>
        @endcan
    </div>
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif

    @php($control = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
    <form method="GET" action="{{ route('admin.providers') }}" class="mt-6 grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100 sm:grid-cols-3" role="search">
        <div class="sm:col-span-2">
            <label for="q" class="mb-1 block text-xs font-medium text-navy-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Name, code or description" class="{{ $control }}">
        </div>
        <div>
            <label for="status" class="mb-1 block text-xs font-medium text-navy-700">Status</label>
            <select id="status" name="status" class="{{ $control }}">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-2 sm:col-span-3 sm:justify-end">
            <a href="{{ route('admin.providers') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Apply</button>
        </div>
    </form>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($providers->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-navy-800">No providers found</p>
                <p class="mt-1 text-sm text-navy-500">{{ array_filter($filters) ? 'Try a different search or filter.' : 'Providers are added by an administrator with “Add provider”.' }}</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($providers as $provider)
                    <li class="flex flex-col gap-2 px-5 py-4 md:flex-row md:items-center" data-provider="{{ $provider->code }}">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('admin.providers.show', $provider) }}" class="block break-words text-sm font-semibold text-navy-900 hover:text-brand-700">{{ $provider->name }}</a>
                            <p class="break-all text-xs text-navy-500">{{ $provider->code }} · {{ $provider->services_count }} {{ Str::plural('service', $provider->services_count) }} · {{ $provider->routes_count }} {{ Str::plural('route', $provider->routes_count) }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @include('admin.providers.status', ['status' => $provider->status])
                            @include('admin.providers.config-badge', ['provider' => $provider])
                            @include('admin.providers.adapter-badge', ['installed' => filled($provider->driver) && in_array($provider->driver, $installedDrivers, true)])
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @include('admin.services.partials.pagination', ['paginator' => $providers])
@endsection
