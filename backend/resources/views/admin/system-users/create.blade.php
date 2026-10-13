@extends('layouts.admin')

@section('title', 'Add system user · Admin · '.config('app.name'))
@section('heading', 'Add system user')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.system-users') }}" class="text-sm text-brand-700 hover:underline">← Back to System Users</a>

        <form method="POST" action="{{ route('admin.system-users.store') }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate>
            @csrf
            @include('admin.system-users.form')
            <div class="mt-2 flex justify-end">
                <x-button class="sm:w-auto">Create system user</x-button>
            </div>
        </form>
    </div>
@endsection
