{{-- Shared catalog fields. $item: existing model or null; $icons: CatalogIcon cases; $withCategory: services only --}}
@php($selectClass = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')

@if ($withCategory ?? false)
    <div class="mb-4">
        <label for="category_id" class="mb-1 block text-sm font-medium text-navy-800">Category</label>
        <select id="category_id" name="category_id" required @class([$selectClass, 'border-red-400' => $errors->has('category_id')])>
            <option value="">Choose a category</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((string) old('category_id', $item?->category_id ?? request('category')) === (string) $category->id)>
                    {{ $category->name }}{{ $category->is_active ? '' : ' (disabled)' }}
                </option>
            @endforeach
        </select>
        @error('category_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
@endif

<x-input name="name" label="Name" :value="$item?->name" autocomplete="off" required />

<div class="-mt-2 mb-4 text-xs text-navy-600" data-slug-note>
    @if ($item)
        Slug: <span class="font-mono text-navy-800">{{ $item->slug }}</span> · locked after creation
    @else
        The slug is generated from the name and cannot be changed later.
    @endif
</div>

<div class="mb-4">
    <label for="description" class="mb-1 block text-sm font-medium text-navy-800">Description (optional)</label>
    <textarea id="description" name="description" rows="3" @class([$selectClass, 'border-red-400' => $errors->has('description')])>{{ old('description', $item?->description) }}</textarea>
    @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</div>

<div class="mb-4 grid gap-4 sm:grid-cols-2">
    <div>
        <label for="icon" class="mb-1 block text-sm font-medium text-navy-800">Icon</label>
        <select id="icon" name="icon" class="{{ $selectClass }}">
            <option value="">Default</option>
            @foreach ($icons as $icon)
                <option value="{{ $icon->value }}" @selected(old('icon', $item?->icon?->value) === $icon->value)>{{ $icon->label() }}</option>
            @endforeach
        </select>
        @error('icon')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <x-input name="sort_order" label="Display order" type="number" :value="$item?->sort_order ?? 0" min="0" />
</div>

@unless ($item)
    <label class="mb-6 flex items-center gap-2 text-sm text-navy-800">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active')) class="h-4 w-4 rounded border-navy-300 text-brand-600 focus:ring-brand-500">
        Active
    </label>
@endunless
