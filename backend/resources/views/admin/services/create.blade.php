@extends('layouts.admin')

@section('title', 'Add service · Admin · '.config('app.name'))
@section('heading', 'Add service')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.services') }}" class="text-sm text-brand-700 hover:underline">← Back to Services</a>
        <form method="POST" action="{{ route('admin.services.store') }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate>
            @csrf
            @include('admin.services.partials.fields', ['item' => null, 'withCategory' => true])
            <div class="flex justify-end"><x-button class="sm:w-auto">Create service</x-button></div>
        </form>
    </div>
@endsection
