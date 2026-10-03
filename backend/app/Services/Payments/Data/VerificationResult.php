<?php

namespace App\Services\Payments\Data;

use App\Support\Payments\GatewayPaymentStatus;

/** Server-side verification answer, normalized by the adapter. Amounts are integer kobo. */
final readonly class VerificationResult
{
    public function __construct(
        public GatewayPaymentStatus $status,
        public ?int $amountKobo = null,
        public ?string $currency = null,
        public ?string $gatewayReference = null,
    ) {}
}
