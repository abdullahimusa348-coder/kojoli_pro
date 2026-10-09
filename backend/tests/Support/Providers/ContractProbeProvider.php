<?php

namespace Tests\Support\Providers;

use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\ProviderContext;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderQueryRequest;
use App\Services\Providers\Data\ProviderResult;
use App\Support\Providers\CredentialKey;
use App\Support\Providers\ProviderCallType;
use Closure;
use Illuminate\Support\Facades\Http;

/**
 * TEST-ONLY adapter used to test the adapter contract kit itself
 * (tests/Support/Providers/AdapterContract.php). By default it follows every
 * rule; each static property breaks one rule at a time. Its endpoints and
 * payloads are invented for tests and describe no real provider: status
 * "delivered" means succeeded, "rejected" means failed_definite, anything
 * else is unknown. Never registered in config/providers.php.
 */
class ContractProbeProvider implements ProviderAdapter
{
    public const API = 'https://api.contract-probe.test';

    public static string $driver = 'contract_probe';

    public static string $label = 'Contract probe (test only)';

    /** @var list<mixed> */
    public static array $services = ['data', 'airtime'];

    /** @var list<mixed> */
    public static array $credentialKeys = [CredentialKey::ApiKey];

    /** @var list<mixed> */
    public static array $hosts = ['api.contract-probe.test'];

    public static int $timeout = 20;

    public static bool $canQuery = true;

    /** How many times purchase() sends its request (more than once breaks the never-resend rule). */
    public static int $sendsPerPurchase = 1;

    /** When set, purchase() first calls this URL directly, bypassing ProviderHttpClient (breaks the declared-hosts rule). */
    public static ?string $directUrl = null;

    /** Replaces the response mapping: fn (int $status, ?array $json, ProviderContext $context): ProviderResult. */
    public static ?Closure $map = null;

    public static function reset(): void
    {
        self::$driver = 'contract_probe';
        self::$label = 'Contract probe (test only)';
        self::$services = ['data', 'airtime'];
        self::$credentialKeys = [CredentialKey::ApiKey];
        self::$hosts = ['api.contract-probe.test'];
        self::$timeout = 20;
        self::$canQuery = true;
        self::$sendsPerPurchase = 1;
        self::$directUrl = null;
        self::$map = null;
    }

    public function driver(): string
    {
        return self::$driver;
    }

    public function label(): string
    {
        return self::$label;
    }

    public function supportedServices(): array
    {
        return self::$services;
    }

    public function credentialKeys(): array
    {
        return self::$credentialKeys;
    }

    public function apiHosts(): array
    {
        return self::$hosts;
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
        return self::$canQuery;
    }

    public function purchase(ProviderPurchaseRequest $request, ProviderContext $context): ProviderResult
    {
        if (self::$directUrl !== null) {
            Http::post(self::$directUrl, ['reference' => $request->requestReference]);
        }
        $response = null;
        for ($i = 0; $i < max(1, self::$sendsPerPurchase); $i++) {
            $response = $context->http->send(ProviderCallType::Purchase, 'POST', self::API.'/buy', $this->apiHosts(), $this->timeoutSeconds(), [
                'headers' => ['Authorization' => 'Bearer '.$context->credential(CredentialKey::ApiKey)],
                'json' => ['reference' => $request->requestReference, 'service' => $request->serviceSlug, 'phone' => $request->recipient,
                    'plan' => $request->providerPlanCode, 'amount' => $request->faceValueKobo ?? $request->amountKobo],
            ]);
        }

        return (self::$map ?? self::map(...))($response->status, $response->json, $context);
    }

    public function query(ProviderQueryRequest $request, ProviderContext $context): ProviderResult
    {
        $response = $context->http->send(ProviderCallType::Query, 'GET', self::API.'/status/'.$request->requestReference, $this->apiHosts(),
            $this->timeoutSeconds(), ['headers' => ['Authorization' => 'Bearer '.$context->credential(CredentialKey::ApiKey)]], retryQuery: true);

        return (self::$map ?? self::map(...))($response->status, $response->json, $context);
    }

    /** @param  array<mixed>|null  $json */
    private static function map(int $status, ?array $json, ProviderContext $context): ProviderResult
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
