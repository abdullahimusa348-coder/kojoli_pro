@extends('layouts.admin')

@section('title', $service->name.' · Services · Admin · '.config('app.name'))
@section('heading', 'Service details')

@section('page')
    <a href="{{ route('admin.services') }}" class="text-sm text-brand-700 hover:underline">← Back to Services</a>
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif

    <section class="mt-4 max-w-3xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-service-details>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 items-center gap-3">
                @include('admin.services.partials.icon', ['icon' => $service->icon])
                <h2 class="min-w-0 break-words text-lg font-semibold text-navy-900">{{ $service->name }}</h2>
            </div>
            <div class="flex flex-wrap gap-2">
                @can(\App\Support\Enums\SystemPermission::ServicesUpdate->value)
                    <a href="{{ route('admin.services.edit', $service) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">Edit</a>
                @endcan
                @include('admin.services.partials.toggle', ['action' => route('admin.services.status', $service), 'active' => $service->is_active])
            </div>
        </div>

        <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Status</dt><dd class="mt-0.5">@include('admin.services.partials.status', ['label' => $service->statusLabel()])</dd></div>
            <div><dt class="text-navy-600">Available</dt><dd class="mt-0.5 font-medium text-navy-900" data-available>{{ $service->isAvailable() ? 'Yes' : 'No' }}</dd></div>
            <div><dt class="text-navy-600">Category</dt><dd class="mt-0.5 font-medium"><a href="{{ route('admin.services.categories.show', $service->category) }}" class="text-brand-700 hover:underline">{{ $service->category->name }}</a></dd></div>
            <div><dt class="text-navy-600">Slug</dt><dd class="mt-0.5 break-all font-mono text-navy-900">{{ $service->slug }}</dd></div>
            <div><dt class="text-navy-600">Display order</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $service->sort_order }}</dd></div>
            <div><dt class="text-navy-600">Last updated</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $service->updated_at?->format('j M Y, H:i') }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-navy-600">Description</dt><dd class="mt-0.5 break-words text-navy-900">{{ $service->description ?: '—' }}</dd></div>
        </dl>
        @if ($service->is_active && ! $service->category->is_active)
            <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">This service is active, but its category is disabled, so it is unavailable.</p>
        @endif
    </section>

    {{-- Products in this service --}}
    <section class="mt-6 max-w-3xl overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="service-products-heading" data-service-products>
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-navy-100 px-5 py-4">
            <h2 id="service-products-heading" class="text-base font-semibold text-navy-900">Products in this service</h2>
            <div class="flex gap-3 text-sm">
                @can(\App\Support\Enums\SystemPermission::ServicesCreate->value)
                    <a href="{{ route('admin.services.products.create', ['service' => $service->id]) }}" class="text-brand-700 hover:underline">Add product</a>
                @endcan
                <a href="{{ route('admin.services.products', ['service' => $service->id]) }}" class="text-brand-700 hover:underline">View in Products</a>
            </div>
        </div>
        @if ($products->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-navy-600">No products in this service yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($products as $product)
                    @php($product->setRelation('service', $service))
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3" data-service-product="{{ $product->code }}">
                        <a href="{{ route('admin.services.products.show', $product) }}" class="min-w-0 break-words text-sm font-medium text-navy-900 hover:text-brand-700">{{ $product->name }}</a>
                        @include('admin.services.partials.status', ['label' => $product->statusLabel()])
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
