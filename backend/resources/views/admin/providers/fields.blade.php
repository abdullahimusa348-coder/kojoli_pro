{{-- Provider fields: details and non-secret settings only (no credential values). $provider: existing or null. --}}
@php($selectClass = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
@php($required = old('required_credentials', $provider?->settings['required_credentials'] ?? []))

<x-input name="name" label="Name" :value="$provider?->name" placeholder="Provider name" autocomplete="off" required />
<div class="-mt-2 mb-4 text-xs text-navy-600" data-code-note>
    @if ($provider)
        Code: <span class="break-all font-mono text-navy-800">{{ $provider->code }}</span> · locked after creation
    @else
        The code is generated from the name and cannot be changed later.
    @endif
</div>

<div class="mb-4">
    <label for="description" class="mb-1 block text-sm font-medium text-navy-800">Description (optional)</label>
    <textarea id="description" name="description" rows="3" class="{{ $selectClass }}">{{ old('description', $provider?->description) }}</textarea>
    @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <x-input name="driver" label="Driver (optional)" :value="$provider?->driver" placeholder="e.g. example_driver" autocomplete="off" />
    <x-input name="sort_order" label="Display order" type="number" :value="$provider?->sort_order ?? 0" min="0" />
</div>
<p class="-mt-2 mb-4 text-xs text-navy-600">Identifier for a future integration adapter. No adapter exists yet, and nothing is sent to providers.</p>

<x-input name="base_url" label="Base URL (optional, non-secret)" type="url" :value="$provider?->baseUrl()" placeholder="https://" autocomplete="off" />

<fieldset class="mb-4">
    <legend class="mb-1 text-sm font-medium text-navy-800">Required credentials</legend>
    <p class="mb-2 text-xs text-navy-600">Which credentials this provider needs. The provider counts as configured once all of them are set (values are entered separately and never shown).</p>
    <div class="grid gap-2 sm:grid-cols-3">
        @foreach ($credentialKeys as $key)
            <label class="flex items-center gap-2 text-sm text-navy-800">
                <input type="checkbox" name="required_credentials[]" value="{{ $key->value }}" class="rounded border-navy-300 text-brand-600 focus:ring-brand-500" @checked(in_array($key->value, (array) $required, true))>
                {{ $key->label() }}
            </label>
        @endforeach
    </div>
    @error('required_credentials')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    @error('required_credentials.*')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</fieldset>
