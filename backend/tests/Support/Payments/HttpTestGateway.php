<?php

namespace Tests\Support\Payments;

use App\Exceptions\Payments\GatewayException;
use App\Exceptions\Payments\MalformedWebhook;
use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\GatewayContext;
use App\Services\Payments\Data\InitializeResult;
use App\Services\Payments\Data\VerificationResult;
use App\Services\Payments\Data\WebhookEvent;
use App\Services\Payments\Data\WebhookRequest;
use App\Support\Payments\GatewayCapability;
use App\Support\Payments\GatewayMode;
use App\Support\Payments\GatewayPaymentStatus;

/**
 * TEST-ONLY adapter that talks HTTP through PaymentHttpClient, so the client's
 * timeouts, retries, host allow-list, error handling and redaction can be
 * tested with Http::fake(). The endpoints and payloads are invented for tests
 * and describe no real gateway.
 */
class HttpTestGateway implements PaymentGateway
{
    public const API = 'https://api.http-gateway.test';

    public static bool $idempotentInit = false;

    public function driver(): string
    {
        return 'http-test';
    }

    public function label(): string
    {
        return 'HTTP test gateway';
    }

    public function capabilities(): array
    {
        return [GatewayCapability::WalletFunding];
    }

    public function credentialKeys(): array
    {
        return ['secret_key'];
    }

    public function requiredCredentials(GatewayMode $mode): array
    {
        return ['secret_key'];
    }

    public function settingsRules(): array
    {
        return [];
    }

    public function checkoutHosts(GatewayMode $mode): array
    {
        return ['pay.http-gateway.test'];
    }

    public function apiHosts(GatewayMode $mode): array
    {
        return ['api.http-gateway.test'];
    }

    public function initializeIsIdempotent(): bool
    {
        return self::$idempotentInit;
    }

    public function initialize(Payment $payment, string $returnUrl, GatewayContext $context): InitializeResult
    {
        $data = $context->http->send('POST', self::API.'/init', $this->apiHosts($context->mode), [
            'headers' => ['Authorization' => 'Bearer '.$context->credential('secret_key')],
            'json' => ['reference' => $payment->reference, 'amount' => $payment->amount_kobo, 'return_url' => $returnUrl],
        ]);
        if (! is_string($data['checkout_url'] ?? null) || ! is_string($data['gateway_reference'] ?? null)) {
            throw new GatewayException('The payment gateway returned an invalid response.');
        }

        return new InitializeResult($data['gateway_reference'], $data['checkout_url']);
    }

    public function verify(Payment $payment, GatewayContext $context): VerificationResult
    {
        $data = $context->http->send('GET', self::API.'/verify/'.$payment->reference, $this->apiHosts($context->mode), [
            'headers' => ['Authorization' => 'Bearer '.$context->credential('secret_key')],
        ], retryable: true);

        $status = match ($data['status'] ?? null) {
            'paid' => GatewayPaymentStatus::Paid,
            'pending' => GatewayPaymentStatus::Pending,
            'failed' => GatewayPaymentStatus::Failed,
            default => throw new GatewayException('The payment gateway returned an invalid response.'),
        };

        return new VerificationResult($status, is_int($data['amount'] ?? null) ? $data['amount'] : null, $data['currency'] ?? null, $data['gateway_reference'] ?? null);
    }

    public function authenticateWebhook(WebhookRequest $request, GatewayContext $context): bool
    {
        return false;
    }

    public function parseWebhook(WebhookRequest $request): WebhookEvent
    {
        throw new MalformedWebhook('Not supported.');
    }
}
