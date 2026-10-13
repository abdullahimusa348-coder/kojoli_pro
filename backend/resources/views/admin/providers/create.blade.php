@extends('layouts.admin')

@section('title', 'Add provider · Admin · '.config('app.name'))
@section('heading', 'Add provider')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.providers') }}" class="text-sm text-brand-700 hover:underline">← Back to Providers</a>
        <form method="POST" action="{{ route('admin.providers.store') }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate>
            @csrf
            @include('admin.providers.fields', ['provider' => null])
            <p class="mb-4 text-xs text-navy-600">New providers start inactive. Credentials are added on the provider page.</p>
            <div class="flex justify-end"><x-button class="sm:w-auto">Create provider</x-button></div>
        </form>
    </div>
@endsection
