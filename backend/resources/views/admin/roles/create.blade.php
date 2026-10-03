@extends('layouts.admin')

@section('title', 'Add role · Admin · '.config('app.name'))
@section('heading', 'Add role')

@section('page')
    <a href="{{ route('admin.roles') }}" class="text-sm text-brand-700 hover:underline">← Back to Roles &amp; Permissions</a>

    <form method="POST" action="{{ route('admin.roles.store') }}" class="mt-4 space-y-6" novalidate>
        @csrf
        <div class="max-w-xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6">
            <x-input name="name" label="Role name" placeholder="e.g. Customer Care" autocomplete="off" required />
            <p class="-mt-2 text-xs text-navy-600">Letters, numbers, spaces, “&amp;” or “-”. Built-in role names are reserved.</p>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6">
            @include('admin.roles.matrix')
        </div>

        <div class="flex justify-end">
            <x-button class="sm:w-auto">Create role</x-button>
        </div>
    </form>
@endsection
