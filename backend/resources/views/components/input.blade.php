@props(['name', 'label', 'type' => 'text', 'value' => null, 'bag' => 'default'])

@php($error = $errors->getBag($bag)->first($name))

<div class="mb-4">
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium text-navy-800">{{ $label }}</label>
    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
        {{ $attributes->class([
            'block w-full rounded-lg border px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200',
            'border-red-400' => $error,
            'border-navy-200' => ! $error,
        ]) }}
        @if ($error) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
    >
    @if ($error)
        <p id="{{ $name }}-error" class="mt-1 text-sm text-red-600">{{ $error }}</p>
    @endif
</div>
