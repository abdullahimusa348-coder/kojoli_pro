<?php

namespace Tests\Support\Providers;

use App\Exceptions\Providers\ProviderCallUncertain;
use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\ProviderContext;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderQueryRequest;
use App\Services\Providers\Data\ProviderResult;
use App\Support\Providers\CredentialKey;
use RuntimeException;

/**
 * TEST-ONLY provider adapter. Never registered in config/providers.php.
 * Each test scripts what the "provider" answers: succeeded, failed_definite,
 * unknown, timeout (no answer) or exception (adapter bug). It makes no HTTP
 * calls and creates no purchases or wallet changes.
 */
class FakeProvider implements ProviderAdapter
{
    /** @var list<string> */
    public static array $purchaseScript = [];

    /** @var list<string> */
    public static array $queryScript = [];

    /** @var list<string> */
    public static array $services = ['data', 'airtime'];

    public static bool $queryable = true;

    /** Milliseconds each purchase or query call takes (lets concurrency tests overlap). */
    public static int $delayMs = 0;

    /** @var list<ProviderPurchaseRequest|ProviderQueryRequest> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$purchaseScript = [];
        self::$queryScript = [];
        self::$services = ['data', 'airtime'];
        self::$queryable = true;
        self::$delayMs = 0;
        self::$calls = [];
    }

    public function driver(): string
    {
        return 'fake-provider';
    }

    public function label(): string
    {
        return 'Fake test provider';
    }

    public function supportedServices(): array
    {
        return self::$services;
    }

    public function credentialKeys(): array
    {
        return [CredentialKey::ApiKey];
    }

    public function apiHosts(): array
    {
        return ['api.fake-provider.test'];
    }

    public function timeoutSeconds(): int
    {
        return 20;
    }

    public function purchaseIsIdempotent(): bool
    {
        return false;
    }

    public function canQuery(): bool
    {
        return self::$queryable;
    }

    public function purchase(ProviderPurchaseRequest $request, ProviderContext $context): ProviderResult
    {
        self::$calls[] = $request;
        if (self::$delayMs > 0) {
            usleep(self::$delayMs * 1000);
        }

        return self::answer(array_shift(self::$purchaseScript) ?? 'unknown', 'FP-'.$request->requestReference);
    }

    public function query(ProviderQueryRequest $request, ProviderContext $context): ProviderResult
    {
        self::$calls[] = $request;
        if (self::$delayMs > 0) {
            usleep(self::$delayMs * 1000);
        }

        return self::answer(array_shift(self::$queryScript) ?? 'unknown', $request->providerReference ?? 'FPQ-'.$request->requestReference);
    }

    private static function answer(string $script, ?string $reference): ProviderResult
    {
        return match ($script) {
            'succeeded' => ProviderResult::succeeded($reference, 'Delivered.'),
            'failed_definite' => ProviderResult::failedDefinite('declined', 'Declined by provider.', $reference),
            'unknown' => ProviderResult::unknown('pending', 'Still processing.', $reference),
            'timeout' => throw new ProviderCallUncertain('The provider could not be reached or did not answer in time.'),
            'exception' => throw new RuntimeException('Adapter bug with secret fake-api-key-NOT-REAL'),
        };
    }
}
