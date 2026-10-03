@extends('layouts.admin')

@section('title', 'Users · Admin · '.config('app.name'))
@section('heading', 'Users')

@section('page')
    <p class="text-sm text-navy-700">Customer accounts. Staff accounts are managed under System Users.</p>

    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif

    <form method="GET" action="{{ route('admin.users') }}" class="mt-6 grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100 sm:grid-cols-4" role="search">
        <div class="sm:col-span-2">
            <label for="q" class="mb-1 block text-xs font-medium text-navy-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Name, email, phone or ID"
                   class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
        </div>
        <div>
            <label for="type" class="mb-1 block text-xs font-medium text-navy-700">Type</label>
            <select id="type" name="type" class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
                <option value="">All types</option>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status" class="mb-1 block text-xs font-medium text-navy-700">Status</label>
            <select id="status" name="status" class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
                <option value="">All statuses</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                <option value="disabled" @selected(($filters['status'] ?? '') === 'disabled')>Disabled</option>
            </select>
        </div>
        <div class="flex gap-2 sm:col-span-4 sm:justify-end">
            <a href="{{ route('admin.users') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Apply</button>
        </div>
    </form>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($customers->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-navy-800">No customers found</p>
                <p class="mt-1 text-sm text-navy-500">{{ ($filters['q'] ?? $filters['type'] ?? $filters['status'] ?? null) ? 'Try a different search or filter.' : 'Customers appear here when they register.' }}</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($customers as $customer)
                    <li data-customer="{{ $customer->id }}">
                        <a href="{{ route('admin.users.show', $customer) }}" class="flex flex-col gap-2 px-5 py-4 hover:bg-navy-50 md:flex-row md:items-center">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-navy-900">{{ $customer->name }}</p>
                                <p class="truncate text-sm text-navy-600">{{ $customer->email }} · {{ $customer->phone }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs md:w-56">
                                <span class="rounded-full bg-brand-50 px-2.5 py-1 font-medium text-brand-800">{{ $customer->user_type->label() }}</span>
                                @if ($customer->isActive())
                                    <span class="rounded-full bg-green-50 px-2.5 py-1 font-medium text-green-800">Active</span>
                                @else
                                    <span class="rounded-full bg-red-50 px-2.5 py-1 font-medium text-red-800">Disabled</span>
                                @endif
                            </div>
                            <p class="text-xs text-navy-500 md:w-44 md:text-right">Joined {{ $customer->created_at?->format('j M Y') }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($customers->hasPages())
        <div class="mt-4 flex items-center justify-between gap-2 text-sm text-navy-700">
            <span>Showing {{ $customers->firstItem() }}–{{ $customers->lastItem() }} of {{ $customers->total() }}</span>
            <div class="flex gap-2">
                @if ($customers->previousPageUrl())
                    <a href="{{ $customers->previousPageUrl() }}" class="rounded-lg px-3 py-1.5 ring-1 ring-navy-200 hover:bg-white">Previous</a>
                @endif
                @if ($customers->nextPageUrl())
                    <a href="{{ $customers->nextPageUrl() }}" class="rounded-lg px-3 py-1.5 ring-1 ring-navy-200 hover:bg-white">Next</a>
                @endif
            </div>
        </div>
    @endif
@endsection
