<?php

namespace Tests\Support\Providers;

use App\Exceptions\Providers\ProviderCallUncertain;
use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\ProviderContext;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderQueryRequest;
use App\Services\Providers\Data\ProviderResult;
use App\Services\Providers\Data\ProviderResultFields;
use App\Support\Providers\CredentialKey;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TEST-ONLY provider adapter. Never registered in config/providers.php.
 * Each test scripts what the "provider" answers: succeeded, failed_definite,
 * unknown, timeout (no answer) or exception (adapter bug). It makes no HTTP
 * calls and creates no purchases or wallet changes.
 * Result fields (Phase 11 CP2): by default it answers outcomes only. A test
 * may script neutral fixture fields (fixtureFields(): keys fixture_1… and
 * random values, never identity-like data) for its succeeded answers.
 */
class FakeProvider implements ProviderAdapter
{
    /** @var list<string> */
    public static array $purchaseScript = [];

    /** @var list<string> */
    public static array $queryScript = [];

    /** @var list<ProviderResultFields|null> attached in order to succeeded answers (purchase or query); none by default */
    public static array $resultScript = [];

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
        self::$resultScript = [];
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

    /**
     * Neutral TEST-ONLY result fields: keys fixture_1… with labels "Fixture 1"…
     * and random values (or the given ones), generated when the test runs.
     * Never realistic identity data; never used outside the test suite.
     *
     * @param  list<string>  $values
     */
    public static function fixtureFields(int $count = 2, array $values = []): ProviderResultFields
    {
        $fields = [];
        for ($i = 1; $i <= $count; $i++) {
            $fields[] = ['key' => "fixture_{$i}", 'label' => "Fixture {$i}", 'value' => $values[$i - 1] ?? 'FIXTURE-'.Str::upper(Str::random(16))];
        }

        return new ProviderResultFields($fields);
    }

    private static function answer(string $script, ?string $reference): ProviderResult
    {
        return match ($script) {
            'succeeded' => ProviderResult::succeeded($reference, 'Delivered.', array_shift(self::$resultScript)),
            'failed_definite' => ProviderResult::failedDefinite('declined', 'Declined by provider.', $reference),
            'unknown' => ProviderResult::unknown('pending', 'Still processing.', $reference),
            'timeout' => throw new ProviderCallUncertain('The provider could not be reached or did not answer in time.'),
            'exception' => throw new RuntimeException('Adapter bug with secret fake-api-key-NOT-REAL'),
        };
    }
}
