@extends('layouts.admin')

@section('title', 'Service categories · Admin · '.config('app.name'))
@section('heading', 'Services')

@section('page')
    @include('admin.services.partials.tabs')

    <form method="GET" action="{{ route('admin.services.categories') }}" class="mt-6 grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100 sm:grid-cols-4" role="search">
        <div class="sm:col-span-2">
            <label for="q" class="mb-1 block text-xs font-medium text-navy-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Name, slug or description"
                   class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
        </div>
        <div>
            <label for="status" class="mb-1 block text-xs font-medium text-navy-700">Status</label>
            <select id="status" name="status" class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
                <option value="">All statuses</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                <option value="disabled" @selected(($filters['status'] ?? '') === 'disabled')>Disabled</option>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <a href="{{ route('admin.services.categories') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Apply</button>
        </div>
    </form>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($categories->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-navy-800">No categories found</p>
                <p class="mt-1 text-sm text-navy-500">Try a different search or filter.</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($categories as $category)
                    <li class="flex flex-col gap-3 px-5 py-4 md:flex-row md:items-center" data-category="{{ $category->slug }}">
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            @include('admin.services.partials.icon', ['icon' => $category->icon])
                            <div class="min-w-0">
                                <a href="{{ route('admin.services.categories.show', $category) }}" class="block break-words text-sm font-semibold text-navy-900 hover:text-brand-700">{{ $category->name }}</a>
                                <p class="break-words text-xs text-navy-500"><span class="font-mono">{{ $category->slug }}</span> · {{ $category->services_count }} {{ \Illuminate\Support\Str::plural('service', $category->services_count) }}</p>
                            </div>
                        </div>
                        <div class="md:w-52">@include('admin.services.partials.status', ['label' => $category->is_active ? 'Active' : 'Disabled'])</div>
                        <div class="flex flex-wrap gap-2 md:w-40 md:justify-end">
                            @can(\App\Support\Enums\SystemPermission::ServicesUpdate->value)
                                <a href="{{ route('admin.services.categories.edit', $category) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">Edit</a>
                            @endcan
                            @include('admin.services.partials.toggle', ['action' => route('admin.services.categories.status', $category), 'active' => $category->is_active])
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @include('admin.services.partials.pagination', ['paginator' => $categories])
@endsection
