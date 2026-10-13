<?php

namespace App\Support\Payments;

/**
 * Sandbox (test) or live. Live calls need the global payments.live_enabled
 * switch, a live-mode gateway and complete live credentials; otherwise the
 * gateway is "not configured". There is never a silent fallback between modes.
 */
enum GatewayMode: string
{
    case Sandbox = 'sandbox';
    case Live = 'live';

    public function label(): string
    {
        return match ($this) {
            self::Sandbox => 'Sandbox (test)',
            self::Live => 'Live',
        };
    }
}
