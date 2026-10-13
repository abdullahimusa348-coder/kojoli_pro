@extends('layouts.admin')

@php($label = \App\Support\Enums\SystemRole::labelFor($role->name))

@section('title', $label.' · Roles · Admin · '.config('app.name'))
@section('heading', $readOnlyReason ? 'View role' : 'Edit role')

@section('page')
    <a href="{{ route('admin.roles') }}" class="text-sm text-brand-700 hover:underline">← Back to Roles &amp; Permissions</a>

    @if ($readOnlyReason)
        <x-alert type="error" class="mt-4">{{ $readOnlyReason }}</x-alert>
    @endif
    @error('role')
        <x-alert type="error" class="mt-4">{{ $message }}</x-alert>
    @enderror

    <form method="POST" action="{{ route('admin.roles.update', $role) }}" class="mt-4 space-y-6" novalidate>
        @csrf
        @method('PUT')
        <fieldset @disabled($readOnlyReason) class="space-y-6">
            <div class="max-w-xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6">
                @if ($nameEditable)
                    <x-input name="name" label="Role name" :value="$role->name" autocomplete="off" required />
                @else
                    <p class="text-sm font-medium text-navy-800">Role name</p>
                    <p class="mt-1 text-base font-semibold text-navy-900">{{ $label }}</p>
                    <p class="mt-1 text-xs text-navy-600">Built-in role: the name cannot be changed.</p>
                @endif
                <p class="mt-3 text-sm text-navy-700">Assigned to <strong>{{ $staffCount }}</strong> staff {{ \Illuminate\Support\Str::plural('member', $staffCount) }}. Changes apply to them on their next page load.</p>
            </div>

            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6">
                @include('admin.roles.matrix')
            </div>
        </fieldset>

        @unless ($readOnlyReason)
            <div class="flex justify-end">
                <x-button class="sm:w-auto">Save role</x-button>
            </div>
        @endunless
    </form>

    @if ($canDelete)
        <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" class="mt-8 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-red-100 sm:p-6">
            @csrf
            @method('DELETE')
            <h2 class="text-base font-semibold text-navy-900">Delete role</h2>
            <p class="mt-1 text-sm text-navy-700">Only possible when no staff member holds this role.</p>
            <button type="submit" class="mt-4 rounded-lg px-4 py-2 text-sm font-semibold text-red-700 ring-1 ring-red-200 hover:bg-red-50"
                    onclick="return confirm('Delete the role {{ e($label) }}?')">Delete role</button>
        </form>
    @endif
@endsection
