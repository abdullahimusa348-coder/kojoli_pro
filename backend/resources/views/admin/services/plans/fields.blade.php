{{-- Plan fields (no price, cost or provider fields). $plan: existing or null. Code locked after creation. --}}
@php($selectClass = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')

<div class="mb-4">
    <label for="product_id" class="mb-1 block text-sm font-medium text-navy-800">Product</label>
    <select id="product_id" name="product_id" required @class([$selectClass, 'border-red-400' => $errors->has('product_id')])>
        <option value="">Choose a product</option>
        @foreach ($products as $product)
            <option value="{{ $product->id }}" @selected((string) old('product_id', $plan?->product_id ?? request('product')) === (string) $product->id)>
                {{ $product->service->name }} · {{ $product->name }}
            </option>
        @endforeach
    </select>
    @error('product_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</div>

<x-input name="name" label="Name" :value="$plan?->name" placeholder="e.g. 1GB – 30 days" autocomplete="off" required />

<div class="-mt-2 mb-4 text-xs text-navy-600" data-code-note>
    @if ($plan)
        Code: <span class="break-all font-mono text-navy-800">{{ $plan->code }}</span> · locked after creation
    @else
        The code is generated from the product and the name and cannot be changed later.
    @endif
</div>

<div class="mb-4 grid gap-4 sm:grid-cols-2">
    <div>
        <label for="amount_type" class="mb-1 block text-sm font-medium text-navy-800">Amount type</label>
        <select id="amount_type" name="amount_type" required @class([$selectClass, 'border-red-400' => $errors->has('amount_type')])>
            @foreach ($amountTypes as $type)
                <option value="{{ $type->value }}" @selected(old('amount_type', $plan?->amount_type?->value ?? 'fixed') === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </select>
        @error('amount_type')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <x-input name="data_volume_mb" label="Data volume in MB (optional)" type="number" :value="$plan?->data_volume_mb" min="1" placeholder="e.g. 1024" />
</div>

<div class="mb-4 grid gap-4 sm:grid-cols-2">
    <div>
        <label for="validity_period" class="mb-1 block text-sm font-medium text-navy-800">Validity period (optional)</label>
        <select id="validity_period" name="validity_period" @class([$selectClass, 'border-red-400' => $errors->has('validity_period')])>
            <option value="">None</option>
            @foreach ($validities as $validity)
                <option value="{{ $validity->value }}" @selected(old('validity_period', $plan?->validity_period?->value) === $validity->value)>{{ $validity->label() }}</option>
            @endforeach
        </select>
        @error('validity_period')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <x-input name="validity_days" label="Validity in days (optional)" type="number" :value="$plan?->validity_days" min="1" placeholder="e.g. 30" />
</div>

<div class="mb-4 grid gap-4 sm:grid-cols-2">
    <x-input name="sort_order" label="Display order" type="number" :value="$plan?->sort_order ?? 0" min="0" />
</div>

<div class="mb-4">
    <label for="description" class="mb-1 block text-sm font-medium text-navy-800">Description (optional)</label>
    <textarea id="description" name="description" rows="3" class="{{ $selectClass }}">{{ old('description', $plan?->description) }}</textarea>
    @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</div>

@unless ($plan)
    <label class="mb-6 flex items-center gap-2 text-sm text-navy-800">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active')) class="h-4 w-4 rounded border-navy-300 text-brand-600 focus:ring-brand-500">
        Active
    </label>
@endunless
