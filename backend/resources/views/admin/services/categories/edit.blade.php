@extends('layouts.admin')

@section('title', 'Edit '.$category->name.' · Admin · '.config('app.name'))
@section('heading', 'Edit category')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.services.categories.show', $category) }}" class="text-sm text-brand-700 hover:underline">← Back to category</a>
        <form method="POST" action="{{ route('admin.services.categories.update', $category) }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate>
            @csrf
            @method('PUT')
            @include('admin.services.partials.fields', ['item' => $category])
            <p class="mb-4 text-xs text-navy-600">Use Enable / Disable on the category page to change its status.</p>
            <div class="flex justify-end"><x-button class="sm:w-auto">Save category</x-button></div>
        </form>
    </div>
@endsection
