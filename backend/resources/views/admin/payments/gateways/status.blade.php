{{-- Gateway status, mode and configuration badges. $gateway, $problem (?string) --}}
<span @class([
    'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-green-50 text-green-800' => $gateway->status === \App\Support\Payments\GatewayStatus::Active,
    'bg-amber-50 text-amber-800' => $gateway->status === \App\Support\Payments\GatewayStatus::Maintenance,
    'bg-navy-50 text-navy-700' => $gateway->status === \App\Support\Payments\GatewayStatus::Inactive,
]) data-gateway-status="{{ $gateway->status->value }}">{{ $gateway->status->label() }}</span>
<span @class([
    'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-red-50 text-red-800' => $gateway->mode === \App\Support\Payments\GatewayMode::Live,
    'bg-sky-50 text-sky-800' => $gateway->mode === \App\Support\Payments\GatewayMode::Sandbox,
]) data-gateway-mode="{{ $gateway->mode->value }}">{{ $gateway->mode->label() }}</span>
<span @class([
    'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-green-50 text-green-800' => $problem === null,
    'bg-amber-50 text-amber-800' => $problem !== null,
]) data-configured="{{ $problem === null ? 'yes' : 'no' }}">{{ $problem === null ? 'Configured' : 'Not configured' }}</span>
