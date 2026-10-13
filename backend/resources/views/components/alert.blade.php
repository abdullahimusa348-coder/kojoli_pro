@props(['type' => 'success'])

<div {{ $attributes->class([
    'mb-4 rounded-lg px-4 py-3 text-sm',
    'bg-green-50 text-green-800' => $type === 'success',
    'bg-red-50 text-red-800' => $type === 'error',
]) }} role="status">
    {{ $slot }}
</div>
