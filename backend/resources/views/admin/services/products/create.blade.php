@extends('layouts.admin')

@section('title', 'Add product · Admin · '.config('app.name'))
@section('heading', 'Add product')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.services.products') }}" class="text-sm text-brand-700 hover:underline">← Back to Products</a>
        <form method="POST" action="{{ route('admin.services.products.store') }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate>
            @csrf
            @include('admin.services.products.fields', ['product' => null])
            <div class="flex justify-end"><x-button class="sm:w-auto">Create product</x-button></div>
        </form>
    </div>
@endsection
