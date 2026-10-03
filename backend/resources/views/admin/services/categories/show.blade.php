@extends('layouts.admin')

@section('title', $category->name.' · Categories · Admin · '.config('app.name'))
@section('heading', 'Category details')

@section('page')
    <a href="{{ route('admin.services.categories') }}" class="text-sm text-brand-700 hover:underline">← Back to Categories</a>
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif

    <section class="mt-4 max-w-3xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-category-details>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 items-center gap-3">
                @include('admin.services.partials.icon', ['icon' => $category->icon])
                <h2 class="min-w-0 break-words text-lg font-semibold text-navy-900">{{ $category->name }}</h2>
            </div>
            <div class="flex flex-wrap gap-2">
                @can(\App\Support\Enums\SystemPermission::ServicesUpdate->value)
                    <a href="{{ route('admin.services.categories.edit', $category) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">Edit</a>
                @endcan
                @include('admin.services.partials.toggle', ['action' => route('admin.services.categories.status', $category), 'active' => $category->is_active])
            </div>
        </div>

        <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Status</dt><dd class="mt-0.5">@include('admin.services.partials.status', ['label' => $category->is_active ? 'Active' : 'Disabled'])</dd></div>
            <div><dt class="text-navy-600">Slug</dt><dd class="mt-0.5 break-all font-mono text-navy-900">{{ $category->slug }}</dd></div>
            <div><dt class="text-navy-600">Display order</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $category->sort_order }}</dd></div>
            <div><dt class="text-navy-600">Last updated</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $category->updated_at?->format('j M Y, H:i') }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-navy-600">Description</dt><dd class="mt-0.5 break-words text-navy-900">{{ $category->description ?: '—' }}</dd></div>
        </dl>
        @unless ($category->is_active)
            <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">This category is disabled, so all of its services are unavailable. Their own status is kept.</p>
        @endunless
    </section>

    <section class="mt-6 max-w-3xl overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="category-services-heading">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-navy-100 px-5 py-4">
            <h2 id="category-services-heading" class="text-base font-semibold text-navy-900">Services in this category</h2>
            <a href="{{ route('admin.services', ['category' => $category->id]) }}" class="text-sm text-brand-700 hover:underline">View in Services</a>
        </div>
        @if ($services->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-navy-600">No services in this category yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($services as $service)
                    @php($service->setRelation('category', $category))
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3" data-category-service="{{ $service->slug }}">
                        <a href="{{ route('admin.services.show', $service) }}" class="min-w-0 break-words text-sm font-medium text-navy-900 hover:text-brand-700">{{ $service->name }}</a>
                        @include('admin.services.partials.status', ['label' => $service->statusLabel()])
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
