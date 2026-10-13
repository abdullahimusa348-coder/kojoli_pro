{{-- Product fields. $product: existing or null. Code is generated on create and locked afterwards. --}}
@php($selectClass = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')

<div class="mb-4">
    <label for="service_id" class="mb-1 block text-sm font-medium text-navy-800">Service</label>
    <select id="service_id" name="service_id" required @class([$selectClass, 'border-red-400' => $errors->has('service_id')])>
        <option value="">Choose a service</option>
        @foreach ($services as $service)
            <option value="{{ $service->id }}" @selected((string) old('service_id', $product?->service_id ?? request('service')) === (string) $service->id)>
                {{ $service->name }} ({{ $service->category->name }})
            </option>
        @endforeach
    </select>
    @error('service_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</div>

<x-input name="name" label="Name" :value="$product?->name" placeholder="e.g. MTN SME" autocomplete="off" required />

<div class="-mt-2 mb-4 text-xs text-navy-600" data-code-note>
    @if ($product)
        Code: <span class="font-mono text-navy-800">{{ $product->code }}</span> · locked after creation
    @else
        The code is generated from the service and the name and cannot be changed later.
    @endif
</div>

<div class="mb-4 grid gap-4 sm:grid-cols-2">
    <div>
        <label for="network" class="mb-1 block text-sm font-medium text-navy-800">Network (optional)</label>
        <select id="network" name="network" class="{{ $selectClass }}">
            <option value="">None</option>
            @foreach ($networks as $network)
                <option value="{{ $network->value }}" @selected(old('network', $product?->network?->value) === $network->value)>{{ $network->label() }}</option>
            @endforeach
        </select>
        @error('network')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <x-input name="sort_order" label="Display order" type="number" :value="$product?->sort_order ?? 0" min="0" />
</div>

<div class="mb-4">
    <label for="description" class="mb-1 block text-sm font-medium text-navy-800">Description (optional)</label>
    <textarea id="description" name="description" rows="3" class="{{ $selectClass }}">{{ old('description', $product?->description) }}</textarea>
    @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</div>

@unless ($product)
    <label class="mb-6 flex items-center gap-2 text-sm text-navy-800">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active')) class="h-4 w-4 rounded border-navy-300 text-brand-600 focus:ring-brand-500">
        Active
    </label>
@endunless
