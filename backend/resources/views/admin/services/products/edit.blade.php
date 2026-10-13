@extends('layouts.admin')

@section('title', 'Edit '.$product->name.' · Admin · '.config('app.name'))
@section('heading', 'Edit product')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.services.products.show', $product) }}" class="text-sm text-brand-700 hover:underline">← Back to product</a>
        <form method="POST" action="{{ route('admin.services.products.update', $product) }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate>
            @csrf
            @method('PUT')
            @include('admin.services.products.fields', ['product' => $product])
            <p class="mb-4 text-xs text-navy-600">Use Enable / Disable on the product page to change its status.</p>
            <div class="flex justify-end"><x-button class="sm:w-auto">Save product</x-button></div>
        </form>
    </div>
@endsection
