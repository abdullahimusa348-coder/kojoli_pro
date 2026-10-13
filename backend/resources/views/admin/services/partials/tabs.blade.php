{{-- Services area tabs: Services | Categories | Products | Plans, with the matching "Add" action. --}}
@php
    $catalogTab = match (true) {
        request()->routeIs('admin.services.categories*') => 'categories',
        request()->routeIs('admin.services.products*') => 'products',
        request()->routeIs('admin.services.plans*') => 'plans',
        default => 'services',
    };
    $catalogTabs = [
        'services' => ['Services', route('admin.services'), 'Add service', route('admin.services.create')],
        'categories' => ['Categories', route('admin.services.categories'), 'Add category', route('admin.services.categories.create')],
        'products' => ['Products', route('admin.services.products'), 'Add product', route('admin.services.products.create')],
        'plans' => ['Plans', route('admin.services.plans'), 'Add plan', route('admin.services.plans.create')],
    ];
@endphp
<div class="flex flex-col gap-3 border-b border-navy-100 sm:flex-row sm:items-end sm:justify-between">
    <nav class="-mb-px flex gap-x-5 gap-y-1 overflow-x-auto" aria-label="Services sections">
        @foreach ($catalogTabs as $key => [$label, $url])
            <a href="{{ $url }}" data-catalog-tab="{{ $key }}"
               @class(['shrink-0 border-b-2 px-1 pb-3 text-sm font-semibold', 'border-brand-600 text-brand-700' => $catalogTab === $key, 'border-transparent text-navy-600 hover:text-navy-900' => $catalogTab !== $key])
               @if ($catalogTab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    @can(\App\Support\Enums\SystemPermission::ServicesCreate->value)
        <a href="{{ $catalogTabs[$catalogTab][3] }}"
           class="mb-3 inline-flex items-center justify-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
            {{ $catalogTabs[$catalogTab][2] }}
        </a>
    @endcan
</div>

<p class="mt-3 text-xs text-navy-500">Catalog structure only: pricing, providers and purchasing are added in later phases.</p>

@if (session('status'))
    <x-alert class="mt-4">{{ session('status') }}</x-alert>
@endif
