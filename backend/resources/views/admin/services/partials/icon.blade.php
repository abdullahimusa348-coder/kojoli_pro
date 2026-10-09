{{-- Catalog icon tile; $icon is a CatalogIcon or null. --}}
<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700" aria-hidden="true">
    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ ($icon ?? \App\Support\Catalog\CatalogIcon::Layers)->path() }}"/>
    </svg>
</span>
