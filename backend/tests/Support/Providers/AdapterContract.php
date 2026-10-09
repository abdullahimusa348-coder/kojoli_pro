<?php

namespace Tests\Support\Providers;

use App\Http\Requests\Admin\Providers\ProviderRequest;
use App\Models\Provider;
use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\ProviderContext;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderQueryRequest;
use App\Services\Providers\Data\ProviderResult;
use App\Services\Providers\ProviderCaller;
use App\Services\Providers\ProviderHttpClient;
use App\Support\Providers\CredentialKey;
use App\Support\Providers\ProviderOutcome;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Shared contract every provider adapter must pass (Phase 10 Step 2, CP1).
 * It knows nothing about any real provider: it checks only the rules of the
 * ProviderAdapter contract that hold for every provider. Each check returns
 * a list of violations (empty = passes), so a failing test names the rule,
 * and the kit itself is tested against a deliberately broken test adapter.
 *
 * - definitionViolations(): what the adapter declares (driver, label,
 *   services, credentials, hosts, timeout).
 * - behaviourViolations(): for adapters that call HTTP. With every HTTP call
 *   faked (nothing leaves the test), answers no provider documents as final
 *   (5xx, non-JSON, empty or undocumented bodies, network failures) must come
 *   out as unknown, never succeeded or failed; requests go only to the
 *   adapter's declared https hosts; a purchase request is never sent twice;
 *   and no credential value or recipient number reaches results or logs.
 * - productionViolations(): the policy for adapters registered in
 *   config/providers.php: both checks above, a driver an administrator can
 *   enter on the provider form, a documented status check (canQuery()), and
 *   no test-only adapters.
 *
 * Real adapters (Phase 10 Step 2 CP3/CP4) must pass this kit in their own
 * tests, in addition to tests built from their provider's documentation.
 */
final class AdapterContract
{
    /** Fits providers.driver (50 characters). */
    private const DRIVER_PATTERN = '/^[a-z][a-z0-9_-]{1,49}$/';

    private const SERVICE_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** A bare lowercase host name with a letter TLD: no scheme, port, path, wildcard or IP address. */
    private const HOST_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    private const RECIPIENT = '08012345678';

    /** @return list<string> */
    public static function definitionViolations(ProviderAdapter $adapter): array
    {
        $violations = [];
        if (! preg_match(self::DRIVER_PATTERN, $adapter->driver())) {
            $violations[] = 'driver() must be 2 to 50 lowercase letters, digits, "-" or "_", starting with a letter.';
        }
        if (trim($adapter->label()) === '') {
            $violations[] = 'label() must not be empty.';
        }

        $services = $adapter->supportedServices();
        if ($services === [] || ! array_is_list($services)) {
            $violations[] = 'supportedServices() must be a non-empty list.';
        } elseif (array_filter($services, fn ($s) => ! is_string($s) || ! preg_match(self::SERVICE_PATTERN, $s)) !== []) {
            $violations[] = 'supportedServices() must list catalog service slugs only.';
        } elseif (count(array_unique($services)) !== count($services)) {
            $violations[] = 'supportedServices() must not repeat a service.';
        }

        $keys = $adapter->credentialKeys();
        if (! array_is_list($keys) || array_filter($keys, fn ($k) => ! $k instanceof CredentialKey) !== []) {
            $violations[] = 'credentialKeys() must list CredentialKey cases only.';
        } elseif (count(array_unique(array_map(fn (CredentialKey $k) => $k->value, $keys))) !== count($keys)) {
            $violations[] = 'credentialKeys() must not repeat a key.';
        }

        $hosts = $adapter->apiHosts();
        if ($hosts === [] || ! array_is_list($hosts)) {
            $violations[] = 'apiHosts() must be a non-empty list of host names.';
        } elseif (array_filter($hosts, fn ($h) => ! is_string($h) || ! preg_match(self::HOST_PATTERN, $h)) !== []) {
            $violations[] = 'apiHosts() must list bare lowercase host names (no scheme, port, path or IP address).';
        } elseif (count(array_unique($hosts)) !== count($hosts)) {
            $violations[] = 'apiHosts() must not repeat a host.';
        }

        [$min, $max] = [config('providers.http.min_timeout'), config('providers.http.max_timeout')];
        if ($adapter->timeoutSeconds() < $min || $adapter->timeoutSeconds() > $max) {
            $violations[] = "timeoutSeconds() must be between {$min} and {$max} seconds.";
        }

        return $violations;
    }

    /**
     * Runs purchase() for every supported service, and query() when the
     * adapter has a documented status check, through ProviderCaller (as the
     * purchase engine does) against each unclear answer. Replaces the Http
     * facade with a fresh fake per answer; nothing is sent anywhere.
     *
     * @return list<string>
     */
    public static function behaviourViolations(ProviderAdapter $adapter): array
    {
        $secrets = [];
        foreach ($adapter->credentialKeys() as $key) {
            // Letters only: a run of 7+ digits would be masked in results and hide a leak.
            $secrets[$key->value] = 'contract-kit-'.str_replace('_', '-', $key->value).'-'.Str::lower(Str::password(16, numbers: false, symbols: false));
        }
        $provider = (new Provider)->forceFill(['code' => 'contract-kit', 'name' => 'Contract kit',
            'settings' => ['base_url' => 'https://'.($adapter->apiHosts()[0] ?? 'invalid.test')]]);
        $context = new ProviderContext($provider, $secrets, app(ProviderHttpClient::class));

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        $retrySleep = config('providers.http.retry_sleep_ms');
        config(['providers.http.retry_sleep_ms' => 0]);
        $originalHttp = Http::getFacadeRoot();

        $violations = [];
        try {
            foreach (self::unclearAnswers() as $answer => $respond) {
                foreach ($adapter->supportedServices() as $service) {
                    $reference = 'PRA-'.Str::upper((string) Str::ulid());
                    $calls = [
                        'purchase' => fn () => app(ProviderCaller::class)->purchase($adapter,
                            new ProviderPurchaseRequest($reference, (string) $service, 'mtn', 'CONTRACT-KIT-PLAN', self::RECIPIENT, 10_000, 10_000), $context),
                    ];
                    if ($adapter->canQuery()) {
                        $calls['query'] = fn () => app(ProviderCaller::class)->query($adapter,
                            new ProviderQueryRequest($reference, 'CONTRACT-KIT-REF', (string) $service), $context);
                    }

                    foreach ($calls as $call => $run) {
                        $sent = [];
                        Http::swap($factory = new HttpFactory(app('events')));
                        $factory->preventStrayRequests();
                        $factory->fake(function (Request $request) use (&$sent, $respond) {
                            $sent[] = $request;

                            return $respond();
                        });

                        $where = "{$call}() for {$service} on {$answer}";
                        $result = $run();
                        $violations = [...$violations, ...self::resultViolations($where, $result, $secrets)];
                        foreach ($sent as $request) {
                            if (! ProviderHttpClient::allows($request->url(), $adapter->apiHosts())) {
                                $violations[] = "{$where}: sent a request to a host the adapter does not declare (https only, no port).";
                            }
                        }
                        $signatures = array_map(fn (Request $r) => $r->method().' '.$r->url().' '.$r->body(), $sent);
                        if ($call === 'purchase' && count(array_unique($signatures)) !== count($signatures)) {
                            $violations[] = "{$where}: sent the same purchase request more than once (purchase calls are never retried).";
                        }
                    }
                }
            }
        } finally {
            config(['providers.http.retry_sleep_ms' => $retrySleep]);
            Http::swap($originalHttp);
        }

        $log = implode("\n", $logged);
        foreach ($secrets as $key => $secret) {
            if (str_contains($log, $secret)) {
                $violations[] = "A log entry contains the {$key} credential value.";
            }
        }
        if (str_contains($log, substr(self::RECIPIENT, 1))) {
            $violations[] = 'A log entry contains the recipient number.';
        }

        return array_values(array_unique($violations));
    }

    /**
     * The policy for adapters registered in config/providers.php (production).
     *
     * @param  array<mixed>  $drivers  driver => adapter class, as in config('providers.drivers')
     * @return list<string>
     */
    public static function productionViolations(array $drivers, bool $allowTestAdapters = false): array
    {
        $violations = [];
        foreach ($drivers as $driver => $class) {
            if (! is_string($driver) || ! is_string($class) || ! is_subclass_of($class, ProviderAdapter::class)) {
                $violations[] = "{$driver}: must map a driver name to a ProviderAdapter class.";

                continue;
            }
            if (! $allowTestAdapters && str_starts_with(ltrim($class, '\\'), 'Tests\\')) {
                $violations[] = "{$driver}: test-only adapters must never be registered for production.";
            }

            $adapter = app($class);
            if ($adapter->driver() !== $driver) {
                $violations[] = "{$driver}: the adapter's driver() must equal its registered name.";
            }
            if (Validator::make(['driver' => $driver], ['driver' => (new ProviderRequest)->rules()['driver']])->fails()) {
                $violations[] = "{$driver}: an administrator cannot enter this driver on the provider form.";
            }
            if (! $adapter->canQuery()) {
                $violations[] = "{$driver}: production adapters must have a documented status check (canQuery()), so unclear outcomes can be settled with the provider.";
            }
            foreach ([...self::definitionViolations($adapter), ...self::behaviourViolations($adapter)] as $violation) {
                $violations[] = "{$driver}: {$violation}";
            }
        }

        return $violations;
    }

    /** @return array<string, Closure> answers no provider documents as final */
    private static function unclearAnswers(): array
    {
        return [
            'HTTP 500 with a JSON body' => fn () => Http::response(['status' => 'error', 'message' => 'Internal error'], 500),
            'HTTP 503 without JSON' => fn () => Http::response('Service unavailable', 503),
            'HTTP 200 without JSON' => fn () => Http::response('<html><body>OK</body></html>', 200),
            'HTTP 200 with an empty JSON body' => fn () => Http::response([], 200),
            'HTTP 200 with an undocumented status' => fn () => Http::response(['status' => 'contract-kit-undocumented', 'code' => 'contract-kit-undocumented'], 200),
            'a connection failure' => fn () => throw new ConnectionException('Connection timed out (contract kit).'),
        ];
    }

    /**
     * @param  array<string, string>  $secrets
     * @return list<string>
     */
    private static function resultViolations(string $where, ProviderResult $result, array $secrets): array
    {
        $violations = [];
        if ($result->outcome !== ProviderOutcome::Unknown) {
            $violations[] = "{$where}: must be unknown, not {$result->outcome->value}.";
        }
        $visible = implode(' ', [$result->message, $result->errorCode, $result->providerReference]);
        foreach ($secrets as $key => $secret) {
            if (str_contains($visible, $secret)) {
                $violations[] = "{$where}: the result contains the {$key} credential value.";
            }
        }

        return $violations;
    }
}
