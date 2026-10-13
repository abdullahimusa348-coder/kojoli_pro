<?php

namespace Tests\Support\Providers;

use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\ProviderContext;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderQueryRequest;
use App\Services\Providers\Data\ProviderResult;
use App\Support\Providers\CredentialKey;
use App\Support\Providers\ProviderCallType;

/**
 * TEST-ONLY adapter that talks HTTP through ProviderHttpClient, so the client
 * (hosts, timeouts, retries, redaction) and the outcome rule can be tested
 * with Http::fake(). Its endpoints and payloads are invented for tests and
 * describe no real provider. Its "documentation": status "delivered" means
 * succeeded and "rejected" means failed_definite; anything else is unknown.
 */
class HttpTestProvider implements ProviderAdapter
{
    public const API = 'https://api.http-provider.test';

    public static int $timeout = 25;

    public function driver(): string
    {
        return 'http-test-provider';
    }

    public function label(): string
    {
        return 'HTTP test provider';
    }

    public function supportedServices(): array
    {
        return ['airtime'];
    }

    public function credentialKeys(): array
    {
        return [CredentialKey::SecretKey];
    }

    public function apiHosts(): array
    {
        return ['api.http-provider.test'];
    }

    public function timeoutSeconds(): int
    {
        return self::$timeout;
    }

    public function purchaseIsIdempotent(): bool
    {
        return false;
    }

    public function canQuery(): bool
    {
        return true;
    }

    public function purchase(ProviderPurchaseRequest $request, ProviderContext $context): ProviderResult
    {
        $response = $context->http->send(ProviderCallType::Purchase, 'POST', self::API.'/topup', $this->apiHosts(), $this->timeoutSeconds(), [
            'headers' => ['Authorization' => 'Bearer '.$context->credential(CredentialKey::SecretKey)],
            'json' => ['reference' => $request->requestReference, 'phone' => $request->recipient, 'amount' => $request->faceValueKobo],
        ]);

        return self::map($response->status, $response->json);
    }

    public function query(ProviderQueryRequest $request, ProviderContext $context): ProviderResult
    {
        $response = $context->http->send(ProviderCallType::Query, 'GET', self::API.'/status/'.$request->requestReference, $this->apiHosts(),
            $this->timeoutSeconds(), ['headers' => ['Authorization' => 'Bearer '.$context->credential(CredentialKey::SecretKey)]], retryQuery: true);

        return self::map($response->status, $response->json);
    }

    /** @param  array<mixed>|null  $json */
    private static function map(int $status, ?array $json): ProviderResult
    {
        if ($status !== 200 || $json === null) {
            return ProviderResult::unknown('http_'.$status, 'Unexpected provider response.');
        }

        return match ($json['status'] ?? null) {
            'delivered' => ProviderResult::succeeded($json['id'] ?? null, 'Delivered.'),
            'rejected' => ProviderResult::failedDefinite($json['code'] ?? 'rejected', $json['message'] ?? null, $json['id'] ?? null),
            default => ProviderResult::unknown('undocumented_status', 'Unexpected provider status.', $json['id'] ?? null),
        };
    }
}
