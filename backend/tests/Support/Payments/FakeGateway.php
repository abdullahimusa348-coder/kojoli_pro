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
use Illuminate\Support\Facades\Cache;

/**
 * TEST-ONLY gateway adapter. Never registered in config/payments.php in
 * production. The "gateway side" lives in the cache (fakegw:{reference}), so
 * tests (and separate concurrency worker processes sharing a database cache)
 * can decide what the gateway reports. Its webhook signature (HMAC-SHA256 of
 * the body with the webhook_secret credential, in X-Fake-Signature) is a test
 * convention, not any real gateway's scheme. It makes no HTTP calls.
 */
class FakeGateway implements PaymentGateway
{
    public const CHECKOUT_HOST = 'checkout.fake-gateway.test';

    public static bool $failInitialize = false;

    public static bool $failVerify = false;

    public static ?string $checkoutUrl = null;

    public static function reset(): void
    {
        self::$failInitialize = false;
        self::$failVerify = false;
        self::$checkoutUrl = null;
    }

    public function driver(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake test gateway';
    }

    public function capabilities(): array
    {
        return [GatewayCapability::WalletFunding];
    }

    public function credentialKeys(): array
    {
        return ['api_key', 'webhook_secret'];
    }

    public function requiredCredentials(GatewayMode $mode): array
    {
        return ['api_key', 'webhook_secret'];
    }

    public function settingsRules(): array
    {
        return ['merchant_label' => ['nullable', 'string', 'max:50']];
    }

    public function checkoutHosts(GatewayMode $mode): array
    {
        return [self::CHECKOUT_HOST];
    }

    public function apiHosts(GatewayMode $mode): array
    {
        return ['api.fake-gateway.test'];
    }

    public function initializeIsIdempotent(): bool
    {
        return false;
    }

    public function initialize(Payment $payment, string $returnUrl, GatewayContext $context): InitializeResult
    {
        self::count('initialize');
        if (self::$failInitialize) {
            throw new GatewayException('The fake gateway refused to start checkout.');
        }

        $gatewayReference = 'FGW-'.$payment->reference;
        Cache::put(self::key($payment->reference), [
            'status' => 'pending',
            'amount' => $payment->amount_kobo,
            'currency' => 'NGN',
            'gateway_reference' => $gatewayReference,
        ]);

        return new InitializeResult($gatewayReference, self::$checkoutUrl ?? 'https://'.self::CHECKOUT_HOST.'/pay/'.$payment->reference);
    }

    public function verify(Payment $payment, GatewayContext $context): VerificationResult
    {
        self::count('verify');
        if (self::$failVerify) {
            throw new GatewayException('The payment gateway could not be reached (network error or timeout).');
        }

        $state = Cache::get(self::key($payment->reference));
        if ($state === null) {
            return new VerificationResult(GatewayPaymentStatus::Unknown, null, null, null);
        }

        return new VerificationResult(GatewayPaymentStatus::from($state['status']), $state['amount'], $state['currency'], $state['gateway_reference']);
    }

    public function authenticateWebhook(WebhookRequest $request, GatewayContext $context): bool
    {
        $secret = $context->credential('webhook_secret');
        $signature = $request->header('x-fake-signature');

        return $secret !== null && $signature !== null && hash_equals(hash_hmac('sha256', $request->body, $secret), $signature);
    }

    public function parseWebhook(WebhookRequest $request): WebhookEvent
    {
        $data = json_decode($request->body, true);
        if (! is_array($data) || ! is_string($data['event_id'] ?? null) || ! is_string($data['reference'] ?? null)) {
            throw new MalformedWebhook('Missing event id or reference.');
        }

        return new WebhookEvent($data['event_id'], $data['reference'], $data['gateway_reference'] ?? null,
            array_intersect_key($data, array_flip(['event_id', 'event', 'reference', 'gateway_reference', 'status'])));
    }

    // ---- test helpers: what the "gateway side" reports ----

    public static function pay(string $reference, ?int $amountKobo = null, string $currency = 'NGN'): void
    {
        $state = Cache::get(self::key($reference)) ?? ['gateway_reference' => 'FGW-'.$reference, 'amount' => 0];
        Cache::put(self::key($reference), ['status' => 'paid', 'amount' => $amountKobo ?? $state['amount'], 'currency' => $currency,
            'gateway_reference' => $state['gateway_reference']]);
    }

    public static function decline(string $reference): void
    {
        $state = Cache::get(self::key($reference));
        Cache::forever(self::key($reference), ['status' => 'failed'] + ($state ?? ['amount' => 0, 'currency' => 'NGN', 'gateway_reference' => 'FGW-'.$reference]));
    }

    public static function forget(string $reference): void
    {
        Cache::forget(self::key($reference));
    }

    public static function calls(string $what): int
    {
        return (int) Cache::get('fakegw:calls:'.$what, 0);
    }

    /** @param  array<string, mixed>  $data */
    public static function body(array $data): string
    {
        return json_encode($data);
    }

    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    private static function count(string $what): void
    {
        Cache::add('fakegw:calls:'.$what, 0, 86400 * 30);
        Cache::increment('fakegw:calls:'.$what);
    }

    private static function key(string $reference): string
    {
        return 'fakegw:'.$reference;
    }
}
