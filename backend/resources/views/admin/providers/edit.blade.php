@extends('layouts.admin')

@section('title', 'Edit '.$provider->name.' · Admin · '.config('app.name'))
@section('heading', 'Edit provider')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.providers.show', $provider) }}" class="text-sm text-brand-700 hover:underline">← Back to provider</a>
        <form method="POST" action="{{ route('admin.providers.update', $provider) }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate>
            @csrf
            @method('PUT')
            @include('admin.providers.fields', ['provider' => $provider])
            <p class="mb-4 text-xs text-navy-600">Use the status actions on the provider page to change its status.</p>
            <div class="flex justify-end"><x-button class="sm:w-auto">Save provider</x-button></div>
        </form>
    </div>
@endsection
