@extends('layouts.admin')

@section('title', 'Edit '.$service->name.' · Admin · '.config('app.name'))
@section('heading', 'Edit service')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.services.show', $service) }}" class="text-sm text-brand-700 hover:underline">← Back to service</a>
        <form method="POST" action="{{ route('admin.services.update', $service) }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate>
            @csrf
            @method('PUT')
            @include('admin.services.partials.fields', ['item' => $service, 'withCategory' => true])
            <p class="mb-4 text-xs text-navy-600">Use Enable / Disable on the service page to change its status.</p>
            <div class="flex justify-end"><x-button class="sm:w-auto">Save service</x-button></div>
        </form>
    </div>
@endsection
