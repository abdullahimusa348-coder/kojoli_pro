<?php

namespace App\Services\Payments\Data;

/**
 * A parsed webhook. Only identifies the payment and the event: its claims are
 * never trusted, the payment is always verified with the gateway first.
 */
final readonly class WebhookEvent
{
    /** @param  array<string, mixed>  $sanitizedPayload  allow-listed fields only */
    public function __construct(
        public string $eventKey,
        public ?string $paymentReference,
        public ?string $gatewayReference,
        public array $sanitizedPayload,
    ) {}
}
