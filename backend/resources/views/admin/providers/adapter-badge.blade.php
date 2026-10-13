{{-- Whether an adapter (provider integration code) is installed for the provider's driver. $installed: bool. --}}
<span @class([
    'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-green-50 text-green-800' => $installed,
    'bg-navy-50 text-navy-700' => ! $installed,
]) data-adapter="{{ $installed ? 'installed' : 'none' }}">{{ $installed ? 'Adapter installed' : 'No adapter' }}</span>
