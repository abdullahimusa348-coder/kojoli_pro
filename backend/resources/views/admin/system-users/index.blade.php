@extends('layouts.admin')

@section('title', 'System Users · Admin · '.config('app.name'))
@section('heading', 'System Users')

@section('page')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-navy-700">Staff accounts for the admin area. Customers are managed separately.</p>
        <a href="{{ route('admin.system-users.create') }}" class="inline-flex items-center justify-center rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
            Add system user
        </a>
    </div>

    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif
    @error('system_user')
        <x-alert type="error" class="mt-4">{{ $message }}</x-alert>
    @enderror

    {{-- Search and filters --}}
    <form method="GET" action="{{ route('admin.system-users') }}" class="mt-6 grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100 sm:grid-cols-4" role="search">
        <div class="sm:col-span-2">
            <label for="q" class="mb-1 block text-xs font-medium text-navy-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Name, email or phone"
                   class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
        </div>
        <div>
            <label for="role" class="mb-1 block text-xs font-medium text-navy-700">Role</label>
            <select id="role" name="role" class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
                <option value="">All roles</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->value }}" @selected(($filters['role'] ?? '') === $role->value)>{{ $role->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status" class="mb-1 block text-xs font-medium text-navy-700">Status</label>
            <select id="status" name="status" class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
                <option value="">All statuses</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                <option value="disabled" @selected(($filters['status'] ?? '') === 'disabled')>Inactive</option>
            </select>
        </div>
        <div class="flex gap-2 sm:col-span-4 sm:justify-end">
            <a href="{{ route('admin.system-users') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Apply</button>
        </div>
    </form>

    {{-- List --}}
    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($staff->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-navy-800">No system users found</p>
                <p class="mt-1 text-sm text-navy-500">Try a different search or filter.</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($staff as $member)
                    @php($role = $member->primaryRole())
                    @php($isSelf = $actor->is($member))
                    @php($canTouch = $actor->isSuperAdmin() || $role !== \App\Support\Enums\SystemRole::SuperAdmin)
                    <li class="flex flex-col gap-3 px-5 py-4 md:flex-row md:items-center" data-system-user="{{ $member->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-navy-900">
                                {{ $member->name }}
                                @if ($isSelf)<span class="ml-1 text-xs font-normal text-navy-500">(you)</span>@endif
                            </p>
                            <p class="truncate text-sm text-navy-600">{{ $member->email }}@if ($member->phone) · {{ $member->phone }}@endif</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs md:w-64">
                            <span class="rounded-full bg-brand-50 px-2.5 py-1 font-medium text-brand-800">{{ $role?->label() ?? 'No role' }}</span>
                            @if ($member->isActive())
                                <span class="rounded-full bg-green-50 px-2.5 py-1 font-medium text-green-800">Active</span>
                            @else
                                <span class="rounded-full bg-navy-100 px-2.5 py-1 font-medium text-navy-700">Inactive</span>
                            @endif
                        </div>
                        <p class="text-xs text-navy-500 md:w-40">
                            {{ $member->last_login_at ? 'Last login '.$member->last_login_at->diffForHumans() : 'Never signed in' }}
                        </p>
                        <div class="flex flex-wrap gap-2 md:w-72 md:justify-end">
                            @if ($canTouch)
                                <a href="{{ route('admin.system-users.edit', $member) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">Edit</a>
                                @unless ($isSelf)
                                    <form method="POST" action="{{ route('admin.system-users.status', $member) }}">
                                        @csrf
                                        @method('PATCH')
                                        @if ($member->isActive())
                                            <input type="hidden" name="status" value="disabled">
                                            <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-navy-800 ring-1 ring-navy-200 hover:bg-navy-50"
                                                    onclick="return confirm('Deactivate {{ e($member->name) }}? They will be signed out and cannot log in.')">Deactivate</button>
                                        @else
                                            <input type="hidden" name="status" value="active">
                                            <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-green-800 ring-1 ring-green-200 hover:bg-green-50">Activate</button>
                                        @endif
                                    </form>
                                    <form method="POST" action="{{ route('admin.system-users.destroy', $member) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-red-700 ring-1 ring-red-200 hover:bg-red-50"
                                                onclick="return confirm('Delete {{ e($member->name) }}? The account is removed from the admin area and can no longer sign in.')">Delete</button>
                                    </form>
                                @endunless
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($staff->hasPages())
        <div class="mt-4 flex items-center justify-between text-sm text-navy-700">
            <span>Showing {{ $staff->firstItem() }}–{{ $staff->lastItem() }} of {{ $staff->total() }}</span>
            <div class="flex gap-2">
                @if ($staff->previousPageUrl())
                    <a href="{{ $staff->previousPageUrl() }}" class="rounded-lg px-3 py-1.5 ring-1 ring-navy-200 hover:bg-white">Previous</a>
                @endif
                @if ($staff->nextPageUrl())
                    <a href="{{ $staff->nextPageUrl() }}" class="rounded-lg px-3 py-1.5 ring-1 ring-navy-200 hover:bg-white">Next</a>
                @endif
            </div>
        </div>
    @endif
@endsection
