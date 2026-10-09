<?php

namespace App\Services\Payments\Contracts;

use App\Exceptions\Payments\GatewayException;
use App\Exceptions\Payments\MalformedWebhook;
use App\Models\Payment;
use App\Services\Payments\Data\GatewayContext;
use App\Services\Payments\Data\InitializeResult;
use App\Services\Payments\Data\VerificationResult;
use App\Services\Payments\Data\WebhookEvent;
use App\Services\Payments\Data\WebhookRequest;
use App\Support\Payments\GatewayCapability;
use App\Support\Payments\GatewayMode;

/**
 * Contract every payment gateway adapter implements. All gateway-specific
 * behaviour (endpoints, authentication, signatures, payload shapes, amount
 * units, status names) lives inside the adapter and must come from the
 * gateway's verified official documentation. The engine never trusts a
 * webhook or a browser return: it always calls verify() before crediting.
 * Adapters make HTTP calls only through GatewayContext::$http.
 */
interface PaymentGateway
{
    /** Stable driver name, e.g. the key in config('payments.drivers'). */
    public function driver(): string;

    public function label(): string;

    /** @return list<GatewayCapability> */
    public function capabilities(): array;

    /** @return list<string> every credential key this adapter accepts */
    public function credentialKeys(): array;

    /** @return list<string> credential keys that must be set for the mode */
    public function requiredCredentials(GatewayMode $mode): array;

    /** @return array<string, list<string>> non-secret setting keys and their validation rules */
    public function settingsRules(): array;

    /** @return list<string> https hosts the customer may be redirected to for checkout in this mode */
    public function checkoutHosts(GatewayMode $mode): array;

    /** @return list<string> https hosts this adapter may call in this mode (enforced by PaymentHttpClient) */
    public function apiHosts(GatewayMode $mode): array;

    /** True only if repeating initialize() with the same merchant reference is documented as safe. */
    public function initializeIsIdempotent(): bool;

    /** @throws GatewayException */
    public function initialize(Payment $payment, string $returnUrl, GatewayContext $context): InitializeResult;

    /** Server-side status check by our reference (and the gateway reference when known). @throws GatewayException */
    public function verify(Payment $payment, GatewayContext $context): VerificationResult;

    /** Authenticates a webhook with the gateway's documented mechanism. */
    public function authenticateWebhook(WebhookRequest $request, GatewayContext $context): bool;

    /** @throws MalformedWebhook */
    public function parseWebhook(WebhookRequest $request): WebhookEvent;
}
