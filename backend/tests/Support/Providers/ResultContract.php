<?php

namespace Tests\Support\Providers;

use App\Models\Provider;
use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\ProviderContext;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderQueryRequest;
use App\Services\Providers\Data\ProviderResult;
use App\Services\Providers\ProviderCaller;
use App\Services\Providers\ProviderHttpClient;
use App\Support\Providers\ProviderOutcome;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Result rules every provider adapter that delivers NIN or BVN results must
 * pass (Phase 11 CP2), on top of AdapterContract, which every adapter passes
 * and which stays unchanged. It knows nothing about any real provider or
 * result format: each adapter's own test supplies the provider's documented
 * "delivered" answer as a closure, and the kit fills it with neutral values
 * generated when it runs (letters only, never identity data) and a generated
 * 11-digit number. Each check returns a list of violations (empty = passes),
 * so a failing test names the rule; the kit itself is tested against a
 * deliberately broken test adapter (ResultProbeProvider).
 *
 * - deliveryViolations(): for each NIN/BVN service the adapter supports,
 *   purchase() and, with a status check, query() go through ProviderCaller
 *   (as the purchase engine does) against the delivered answer. They must
 *   come out succeeded with result fields that carry every delivered value
 *   (a success without its result leaves the purchase unclear); no result
 *   value, media or the purchased number may reach the outcome's message,
 *   error code or provider reference (stored on the attempt and shown to
 *   staff), its JSON or debug output, or any log entry; and an answer that
 *   also carries media (a photo or document) still succeeds with the text,
 *   without the media.
 * - unclearViolations(): answers no provider documents as final come out as
 *   unknown, without result fields, and never echo the purchased number.
 * - productionViolations(): both checks for every adapter registered in
 *   config/providers.php that supports NIN or BVN; such an adapter cannot
 *   pass without its documented delivered answer in the result contract test.
 *
 * "No record found" answers are not covered: by default they are a definite
 * failure with a refund, and only the provider's official documentation and
 * an explicit approval can change that for its adapter.
 */
final class ResultContract
{
    /** The services whose purchases deliver a result. */
    public const SERVICES = ['nin', 'bvn'];

    /** A copied piece of media at least this long is detected. */
    private const MEDIA_WINDOW = 32;

    /**
     * $delivered is fn (string $service, string $call, list<string> $values, string $number, ?string $media): the
     * provider's documented delivered answer (an Http::response()) for a "purchase" or "query" call of $service, with
     * $values where its documentation puts result text, $number where it echoes the purchased number (if it does) and
     * $media where it puts a photo or document (if it does; null when not asked for).
     *
     * @return list<string>
     */
    public static function deliveryViolations(ProviderAdapter $adapter, Closure $delivered): array
    {
        $violations = [];
        $logs = self::recordLogs();
        $leaks = ['values' => [], 'numbers' => [], 'media' => []];

        self::faking(function () use ($adapter, $delivered, &$violations, &$leaks) {
            foreach (self::services($adapter) as $service) {
                $number = self::number();
                $values = [self::value(), self::value(), self::value()];
                $media = 'data:image/jpeg;base64,'.base64_encode(random_bytes(300));
                $leaks['values'] = [...$leaks['values'], ...$values];
                $leaks['numbers'][] = $number;
                $leaks['media'][] = $media;

                foreach ([null, $media] as $withMedia) {
                    foreach (self::calls($adapter) as $call) {
                        $where = "{$call}() for {$service} on the delivered answer".($withMedia === null ? '' : ' with media');
                        $result = self::run($adapter, $call, $service, $number,
                            fn () => $delivered($service, $call, $values, $number, $withMedia));
                        $violations = [...$violations, ...self::deliveredViolations($where, $result, $values, $number, $withMedia)];
                    }
                }
            }
        });

        $log = implode("\n", $logs->getArrayCopy());
        if (self::containsAny($log, $leaks['values'])) {
            $violations[] = 'A log entry contains result text.';
        }
        if (array_filter($leaks['numbers'], fn (string $number) => self::containsNumber($log, $number)) !== []) {
            $violations[] = 'A log entry contains the purchased number.';
        }
        if (array_filter($leaks['media'], fn (string $media) => self::containsMedia($log, $media)) !== []) {
            $violations[] = 'A log entry contains media.';
        }

        return array_values(array_unique($violations));
    }

    /** @return list<string> */
    public static function unclearViolations(ProviderAdapter $adapter): array
    {
        $violations = [];
        $logs = self::recordLogs();
        $numbers = [];

        self::faking(function () use ($adapter, &$violations, &$numbers) {
            foreach (self::unclearAnswers() as $answer => $respond) {
                foreach (self::services($adapter) as $service) {
                    $numbers[] = $number = self::number();
                    foreach (self::calls($adapter) as $call) {
                        $where = "{$call}() for {$service} on {$answer}";
                        $result = self::run($adapter, $call, $service, $number, $respond);
                        if ($result->outcome !== ProviderOutcome::Unknown) {
                            $violations[] = "{$where}: must be unknown, not {$result->outcome->value}.";
                        }
                        if ($result->fields !== null) {
                            $violations[] = "{$where}: carries result fields, which only come with a success.";
                        }
                        if (self::containsNumber(self::visible($result), $number)) {
                            $violations[] = "{$where}: the outcome's message, error code or provider reference contains the purchased number.";
                        }
                    }
                }
            }
        });

        $log = implode("\n", $logs->getArrayCopy());
        if (array_filter($numbers, fn (string $number) => self::containsNumber($log, $number)) !== []) {
            $violations[] = 'A log entry contains the purchased number.';
        }

        return array_values(array_unique($violations));
    }

    /**
     * The result policy for adapters registered in config/providers.php: every
     * adapter that supports NIN or BVN passes both checks with its documented
     * delivered answer. Other adapters have no result rules (AdapterContract
     * reports entries that are not adapters).
     *
     * @param  array<mixed>  $drivers  driver => adapter class, as in config('providers.drivers')
     * @param  array<string, Closure>  $deliveredAnswers  driver => its delivered answer (see deliveryViolations())
     * @return list<string>
     */
    public static function productionViolations(array $drivers, array $deliveredAnswers): array
    {
        $violations = [];
        foreach ($drivers as $driver => $class) {
            if (! is_string($class) || ! is_subclass_of($class, ProviderAdapter::class) || self::services($adapter = app($class)) === []) {
                continue;
            }
            if (! isset($deliveredAnswers[$driver])) {
                $violations[] = "{$driver}: delivers NIN/BVN results, but the result contract test has no documented delivered answer for it.";

                continue;
            }
            foreach ([...self::deliveryViolations($adapter, $deliveredAnswers[$driver]), ...self::unclearViolations($adapter)] as $violation) {
                $violations[] = "{$driver}: {$violation}";
            }
        }

        return $violations;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function deliveredViolations(string $where, ProviderResult $result, array $values, string $number, ?string $media): array
    {
        $violations = [];
        if ($result->outcome !== ProviderOutcome::Succeeded) {
            $violations[] = "{$where}: must be succeeded with the result, not {$result->outcome->value}.";
        } elseif ($result->fields === null) {
            $violations[] = "{$where}: reported success without the result fields (the purchase would stay unclear).";
        } else {
            $stored = implode("\n", array_merge(...array_map(fn (array $field) => [$field['label'], $field['value']], $result->fields->all())));
            if (array_filter($values, fn (string $value) => ! str_contains($stored, $value)) !== []) {
                $violations[] = "{$where}: the result fields do not carry the delivered result text.";
            }
            if ($media !== null && self::containsMedia($stored, $media)) {
                $violations[] = "{$where}: media reached the result fields (text only).";
            }
        }

        $visible = self::visible($result);
        if (self::containsAny($visible, $values) || ($media !== null && self::containsMedia($visible, $media))) {
            $violations[] = "{$where}: the outcome's message, error code or provider reference contains result data.";
        }
        if (self::containsNumber($visible, $number)) {
            $violations[] = "{$where}: the outcome's message, error code or provider reference contains the purchased number.";
        }
        ob_start();
        var_dump($result);
        $printed = implode("\n", [json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), print_r($result, true),
            var_export($result, true), ob_get_clean()]);
        if (self::containsAny($printed, $values) || ($media !== null && self::containsMedia($printed, $media))) {
            $violations[] = "{$where}: the outcome's JSON or debug output contains result data.";
        }

        return $violations;
    }

    /** Runs one call through ProviderCaller against $respond, with a fresh HTTP fake that lets nothing out. */
    private static function run(ProviderAdapter $adapter, string $call, string $service, string $number, Closure $respond): ProviderResult
    {
        Http::swap($factory = new HttpFactory(app('events')));
        $factory->preventStrayRequests();
        $factory->fake(fn () => $respond());

        $reference = 'PRR-'.Str::upper((string) Str::ulid());
        $context = new ProviderContext((new Provider)->forceFill(['code' => 'result-kit', 'name' => 'Result kit',
            'settings' => ['base_url' => 'https://'.($adapter->apiHosts()[0] ?? 'invalid.test')]]),
            array_fill_keys(array_map(fn ($key) => $key->value, $adapter->credentialKeys()), 'result-kit-credential'), app(ProviderHttpClient::class));

        return $call === 'purchase'
            ? app(ProviderCaller::class)->purchase($adapter,
                new ProviderPurchaseRequest($reference, $service, null, 'RESULT-KIT-PLAN', $number, 15_000, null, $service), $context)
            : app(ProviderCaller::class)->query($adapter, new ProviderQueryRequest($reference, 'RESULT-KIT-REF', $service), $context);
    }

    /** Runs $checks with query retries not sleeping, and puts the original HTTP client back. */
    private static function faking(Closure $checks): void
    {
        $retrySleep = config('providers.http.retry_sleep_ms');
        config(['providers.http.retry_sleep_ms' => 0]);
        $originalHttp = Http::getFacadeRoot();
        try {
            $checks();
        } finally {
            config(['providers.http.retry_sleep_ms' => $retrySleep]);
            Http::swap($originalHttp);
        }
    }

    /** @return list<string> the NIN/BVN services the adapter supports */
    private static function services(ProviderAdapter $adapter): array
    {
        return array_values(array_intersect(self::SERVICES, array_filter($adapter->supportedServices(), 'is_string')));
    }

    /** @return list<string> */
    private static function calls(ProviderAdapter $adapter): array
    {
        return $adapter->canQuery() ? ['purchase', 'query'] : ['purchase'];
    }

    /** @return array<string, Closure> answers no provider documents as final */
    private static function unclearAnswers(): array
    {
        return [
            'HTTP 500 with a JSON body' => fn () => Http::response(['status' => 'error', 'message' => 'Internal error'], 500),
            'HTTP 503 without JSON' => fn () => Http::response('Service unavailable', 503),
            'HTTP 200 without JSON' => fn () => Http::response('<html><body>OK</body></html>', 200),
            'HTTP 200 with an empty JSON body' => fn () => Http::response([], 200),
            'HTTP 200 with an undocumented status' => fn () => Http::response(['status' => 'result-kit-undocumented', 'code' => 'result-kit-undocumented'], 200),
            'a connection failure' => fn () => throw new ConnectionException('Connection timed out (result kit).'),
        ];
    }

    /** Records every log line (message and context, slashes unescaped) written from now on. */
    private static function recordLogs(): \ArrayObject
    {
        $lines = new \ArrayObject;
        Event::listen(MessageLogged::class, fn (MessageLogged $event) => $lines->append(
            $event->message.' '.json_encode($event->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

        return $lines;
    }

    /** What the engine stores on the attempt and staff can see. */
    private static function visible(ProviderResult $result): string
    {
        return implode(' ', [$result->message, $result->errorCode, $result->providerReference]);
    }

    /** A neutral test value: letters only, so neither digit masking nor JSON escaping can hide a leak. */
    private static function value(): string
    {
        return 'resultkit'.Str::lower(Str::password(14, numbers: false, symbols: false));
    }

    /** A generated 11-digit number (starts 1-9, so it never looks like a phone). */
    private static function number(): string
    {
        return (string) random_int(10_000_000_000, 99_999_999_999);
    }

    /** @param  list<string>  $needles */
    private static function containsAny(string $text, array $needles): bool
    {
        return array_filter($needles, fn (string $needle) => str_contains($text, $needle)) !== [];
    }

    /** The number in $text, also with up to three other characters between its digits (spaced, dashed, dotted or escaped). */
    private static function containsNumber(string $text, string $number): bool
    {
        return preg_match('/'.implode('\D{0,3}', str_split($number)).'/', $text) === 1;
    }

    /** Any copied piece of the media payload (MEDIA_WINDOW characters or more) in $text. */
    private static function containsMedia(string $text, string $media): bool
    {
        $payload = Str::after($media, ',');
        for ($i = 0; $i + self::MEDIA_WINDOW <= strlen($payload); $i += intdiv(self::MEDIA_WINDOW, 2)) {
            if (str_contains($text, substr($payload, $i, self::MEDIA_WINDOW))) {
                return true;
            }
        }

        return false;
    }
}
