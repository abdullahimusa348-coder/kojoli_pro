{{-- Status badge. $label: Active | Disabled | Active (… disabled) --}}
<span @class([
    'inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold',
    'bg-green-50 text-green-800' => $label === 'Active',
    'bg-navy-100 text-navy-700' => $label === 'Disabled',
    'bg-amber-50 text-amber-800' => str_starts_with($label, 'Active ('),
]) data-status="{{ $label }}">{{ $label }}</span>
