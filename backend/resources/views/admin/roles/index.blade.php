@extends('layouts.admin')

@section('title', 'Roles & Permissions · Admin · '.config('app.name'))
@section('heading', 'Roles & Permissions')

@section('page')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-navy-700">Each staff member has one role. A role’s permissions decide what they can see and do in the admin area.</p>
        @can(\App\Support\Enums\SystemPermission::RolesCreate->value)
            <a href="{{ route('admin.roles.create') }}" class="inline-flex shrink-0 items-center justify-center rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">Add role</a>
        @endcan
    </div>

    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif
    @error('role')
        <x-alert type="error" class="mt-4">{{ $message }}</x-alert>
    @enderror

    <form method="GET" action="{{ route('admin.roles') }}" class="mt-6 grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100 sm:grid-cols-4" role="search">
        <div class="sm:col-span-2">
            <label for="q" class="mb-1 block text-xs font-medium text-navy-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Role name"
                   class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
        </div>
        <div>
            <label for="type" class="mb-1 block text-xs font-medium text-navy-700">Type</label>
            <select id="type" name="type" class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
                <option value="">All roles</option>
                <option value="built-in" @selected(($filters['type'] ?? '') === 'built-in')>Built-in</option>
                <option value="custom" @selected(($filters['type'] ?? '') === 'custom')>Custom</option>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <a href="{{ route('admin.roles') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Apply</button>
        </div>
    </form>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($roles->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-navy-800">No roles found</p>
                <p class="mt-1 text-sm text-navy-500">Try a different search or filter.</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($roles as $role)
                    @php($isSuper = $role->name === \App\Support\Enums\SystemRole::SuperAdmin->value)
                    @php($isBuiltIn = \App\Support\Enums\SystemRole::isBuiltIn($role->name))
                    <li class="flex flex-col gap-3 px-5 py-4 md:flex-row md:items-center" data-role="{{ $role->name }}">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-navy-900">
                                {{ \App\Support\Enums\SystemRole::labelFor($role->name) }}
                                @if ($actor->hasRole($role))<span class="ml-1 text-xs font-normal text-navy-500">(your role)</span>@endif
                            </p>
                            <p class="text-xs text-navy-500">{{ $isBuiltIn ? 'Built-in' : 'Custom' }} role</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs md:w-80">
                            <span class="rounded-full bg-brand-50 px-2.5 py-1 font-medium text-brand-800">
                                {{ $isSuper ? 'All permissions' : $role->permissions_count.' of '.$totalPermissions.' permissions' }}
                            </span>
                            <span class="rounded-full bg-navy-100 px-2.5 py-1 font-medium text-navy-700">
                                {{ $staffCounts[$role->id] ?? 0 }} staff
                            </span>
                        </div>
                        <div class="md:w-28 md:text-right">
                            <a href="{{ route('admin.roles.edit', $role) }}" class="inline-flex rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">
                                {{ $isSuper || ! $actor->can(\App\Support\Enums\SystemPermission::RolesUpdate->value) ? 'View' : 'Edit' }}
                            </a>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <p class="mt-4 text-xs text-navy-500">Roles cannot be disabled yet: to stop a role being used, move its staff to another role, then delete it (custom roles only).</p>
@endsection
