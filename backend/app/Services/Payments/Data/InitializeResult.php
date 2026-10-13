<?php

namespace App\Services\Payments\Data;

/** Gateway answer to initialization: its own reference and the checkout URL the customer is sent to. */
final readonly class InitializeResult
{
    public function __construct(
        public string $gatewayReference,
        public string $checkoutUrl,
    ) {}
}
