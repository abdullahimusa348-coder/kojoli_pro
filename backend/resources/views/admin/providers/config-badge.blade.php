{{-- Derived configuration badge (never shows credential values). $provider --}}
<span @class([
    'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-green-50 text-green-800' => $provider->isConfigured(),
    'bg-amber-50 text-amber-800' => ! $provider->isConfigured(),
]) data-configured="{{ $provider->isConfigured() ? 'yes' : 'no' }}">{{ $provider->isConfigured() ? 'Configured' : 'Not configured' }}</span>
