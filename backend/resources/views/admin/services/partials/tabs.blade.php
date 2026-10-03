{{-- Services area tabs: Services | Categories, with the matching "Add" action. --}}
@php($onCategories = request()->routeIs('admin.services.categories*'))
<div class="flex flex-col gap-3 border-b border-navy-100 sm:flex-row sm:items-end sm:justify-between">
    <nav class="-mb-px flex gap-6" aria-label="Services sections">
        <a href="{{ route('admin.services') }}" data-catalog-tab="services"
           @class(['border-b-2 px-1 pb-3 text-sm font-semibold', 'border-brand-600 text-brand-700' => ! $onCategories, 'border-transparent text-navy-600 hover:text-navy-900' => $onCategories])
           @if (! $onCategories) aria-current="page" @endif>Services</a>
        <a href="{{ route('admin.services.categories') }}" data-catalog-tab="categories"
           @class(['border-b-2 px-1 pb-3 text-sm font-semibold', 'border-brand-600 text-brand-700' => $onCategories, 'border-transparent text-navy-600 hover:text-navy-900' => ! $onCategories])
           @if ($onCategories) aria-current="page" @endif>Categories</a>
    </nav>
    @can(\App\Support\Enums\SystemPermission::ServicesCreate->value)
        <a href="{{ $onCategories ? route('admin.services.categories.create') : route('admin.services.create') }}"
           class="mb-3 inline-flex items-center justify-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
            {{ $onCategories ? 'Add category' : 'Add service' }}
        </a>
    @endcan
</div>

<p class="mt-3 text-xs text-navy-500">Catalog only: plans, pricing, providers and purchasing are added in later phases.</p>

@if (session('status'))
    <x-alert class="mt-4">{{ session('status') }}</x-alert>
@endif
