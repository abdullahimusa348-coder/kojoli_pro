@extends('layouts.admin')

@section('title', $product->name.' · Products · Admin · '.config('app.name'))
@section('heading', 'Product details')

@section('page')
    <a href="{{ route('admin.services.products') }}" class="text-sm text-brand-700 hover:underline">← Back to Products</a>
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif

    <section class="mt-4 max-w-3xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-product-details>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h2 class="min-w-0 break-words text-lg font-semibold text-navy-900">{{ $product->name }}</h2>
            <div class="flex flex-wrap gap-2">
                @can(\App\Support\Enums\SystemPermission::ServicesUpdate->value)
                    <a href="{{ route('admin.services.products.edit', $product) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">Edit</a>
                @endcan
                @include('admin.services.partials.toggle', ['action' => route('admin.services.products.status', $product), 'active' => $product->is_active])
            </div>
        </div>

        <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Status</dt><dd class="mt-0.5">@include('admin.services.partials.status', ['label' => $product->statusLabel()])</dd></div>
            <div><dt class="text-navy-600">Available</dt><dd class="mt-0.5 font-medium text-navy-900" data-available>{{ $product->isAvailable() ? 'Yes' : 'No' }}</dd></div>
            <div><dt class="text-navy-600">Service</dt><dd class="mt-0.5 font-medium"><a href="{{ route('admin.services.show', $product->service) }}" class="text-brand-700 hover:underline">{{ $product->service->name }}</a> <span class="text-navy-500">({{ $product->service->category->name }})</span></dd></div>
            <div><dt class="text-navy-600">Network</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $product->network?->label() ?? '—' }}</dd></div>
            <div><dt class="text-navy-600">Code</dt><dd class="mt-0.5 break-all font-mono text-navy-900">{{ $product->code }}</dd></div>
            <div><dt class="text-navy-600">Display order</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $product->sort_order }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-navy-600">Description</dt><dd class="mt-0.5 break-words text-navy-900">{{ $product->description ?: '—' }}</dd></div>
        </dl>
        @if ($product->is_active && ! $product->isAvailable())
            <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">This product is active, but its service or category is disabled, so it is unavailable.</p>
        @endif
    </section>

    <section class="mt-6 max-w-3xl overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="product-plans-heading" data-product-plans>
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-navy-100 px-5 py-4">
            <h2 id="product-plans-heading" class="text-base font-semibold text-navy-900">Plans in this product</h2>
            <div class="flex gap-3 text-sm">
                @can(\App\Support\Enums\SystemPermission::ServicesCreate->value)
                    <a href="{{ route('admin.services.plans.create', ['product' => $product->id]) }}" class="text-brand-700 hover:underline">Add plan</a>
                @endcan
                <a href="{{ route('admin.services.plans', ['product' => $product->id]) }}" class="text-brand-700 hover:underline">View in Plans</a>
            </div>
        </div>
        @if ($plans->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-navy-600">No plans in this product yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($plans as $plan)
                    @php($plan->setRelation('product', $product))
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3" data-product-plan="{{ $plan->code }}">
                        <a href="{{ route('admin.services.plans.show', $plan) }}" class="min-w-0 break-words text-sm font-medium text-navy-900 hover:text-brand-700">{{ $plan->name }}</a>
                        @include('admin.services.partials.status', ['label' => $plan->statusLabel()])
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
