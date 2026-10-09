@extends('layouts.admin')

@section('title', 'Edit '.$staff->name.' · Admin · '.config('app.name'))
@section('heading', 'Edit system user')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.system-users') }}" class="text-sm text-brand-700 hover:underline">← Back to System Users</a>

        @error('system_user')
            <x-alert type="error" class="mt-4">{{ $message }}</x-alert>
        @enderror

        <div class="mt-4 rounded-2xl bg-white p-5 text-sm shadow-sm ring-1 ring-navy-100 sm:p-6">
            <dl class="grid grid-cols-2 gap-2">
                <dt class="text-navy-600">Status</dt><dd class="font-medium text-navy-900">{{ $staff->isActive() ? 'Active' : 'Inactive' }}</dd>
                <dt class="text-navy-600">Last login</dt><dd class="font-medium text-navy-900">{{ $staff->last_login_at?->format('j M Y, H:i') ?? 'Never' }}</dd>
                <dt class="text-navy-600">Created</dt><dd class="font-medium text-navy-900">{{ $staff->created_at?->format('j M Y') }}</dd>
            </dl>
        </div>

        <form method="POST" action="{{ route('admin.system-users.update', $staff) }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate>
            @csrf
            @method('PUT')
            @include('admin.system-users.form')
            <div class="mt-2 flex justify-end">
                <x-button class="sm:w-auto">Save changes</x-button>
            </div>
        </form>
    </div>
@endsection
