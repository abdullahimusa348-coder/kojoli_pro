@extends('layouts.admin')

@section('title', 'Add category · Admin · '.config('app.name'))
@section('heading', 'Add category')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.services.categories') }}" class="text-sm text-brand-700 hover:underline">← Back to Categories</a>
        <form method="POST" action="{{ route('admin.services.categories.store') }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate>
            @csrf
            @include('admin.services.partials.fields', ['item' => null])
            <div class="flex justify-end"><x-button class="sm:w-auto">Create category</x-button></div>
        </form>
    </div>
@endsection
