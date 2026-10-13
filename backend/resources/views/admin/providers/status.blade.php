{{-- Provider status badge. $status: ProviderStatus --}}
<span @class([
    'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-green-50 text-green-800' => $status === \App\Support\Providers\ProviderStatus::Active,
    'bg-amber-50 text-amber-800' => $status === \App\Support\Providers\ProviderStatus::Maintenance,
    'bg-navy-50 text-navy-700' => $status === \App\Support\Providers\ProviderStatus::Inactive,
]) data-provider-status="{{ $status->value }}">{{ $status->label() }}</span>
