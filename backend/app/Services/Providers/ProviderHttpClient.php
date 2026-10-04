<?php

namespace App\Services\Providers;

use App\Exceptions\Providers\ProviderCallUncertain;
use App\Exceptions\Providers\ProviderRequestRefused;
use App\Services\Providers\Data\ProviderHttpResponse;
use App\Support\Providers\ProviderCallType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only place provider adapters talk to the outside world. Separate from
 * the payment gateway client (Phase 9).
 * - https only, and only to hosts the adapter declared (no admin-entered
 *   URLs, so no SSRF);
 * - short connect timeout; the request timeout comes from the adapter,
 *   clamped to config('providers.http');
 * - purchase calls are never retried; query calls are retried on network
 *   errors and 5xx only when the caller explicitly allows it;
 * - a timeout or connection failure surfaces as ProviderCallUncertain (the
 *   outcome is unknown, never "failed");
 * - logs carry host and masked path only, with credentials, tokens and
 *   customer data (phone, meter, account numbers, names, emails) redacted.
 */
class ProviderHttpClient
{
    private const SENSITIVE = '/(authori[sz]ation|secret|token|password|passcode|api[_-]?key|private|signature|pin|cvv|cvc|card|pan|expiry|bvn|nin|phone|msisdn|mobile|recipient|customer|email|name|address|meter|smartcard|iuc|account|beneficiary'
        .'|birth|dob|date[_-]?of[_-]?birth|gender|sex|photo|image|picture|base64|residence|nationality|marital|religion|tracking|document|slip|serial)/i';

    /** Logged string values longer than this are omitted (identity results, media or other bulky provider data). */
    private const MAX_LOGGED_LENGTH = 200;

    /**
     * @param  list<string>  $allowedHosts
     * @param  array<string, mixed>  $options  headers, json, form, query, basic_auth [user, pass]
     *
     * @throws ProviderRequestRefused when the URL is not https or the host is not declared (nothing is sent)
     * @throws ProviderCallUncertain on timeout or connection failure
     */
    public function send(ProviderCallType $type, string $method, string $url, array $allowedHosts, int $timeoutSeconds,
        array $options = [], bool $retryQuery = false): ProviderHttpResponse
    {
        if (! self::allows($url, $allowedHosts)) {
            throw new ProviderRequestRefused('Refused to call a host the provider adapter has not declared.');
        }
        $parts = parse_url($url);

        $config = config('providers.http');
        $request = Http::connectTimeout($config['connect_timeout'])->timeout(self::timeout($timeoutSeconds))
            ->acceptJson()->withHeaders($options['headers'] ?? []);
        if (isset($options['basic_auth'])) {
            $request = $request->withBasicAuth(...$options['basic_auth']);
        }
        if (isset($options['form'])) {
            $request = $request->asForm();
        }
        if ($type === ProviderCallType::Query && $retryQuery) {
            $request = $request->retry($config['query_retries'] + 1, $config['retry_sleep_ms'],
                fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), throw: false);
        }

        $body = $options['json'] ?? $options['form'] ?? null;
        $started = microtime(true);
        try {
            $response = $request->send(strtoupper($method), $url, array_filter([
                isset($options['form']) ? 'form_params' : 'json' => $body,
                'query' => $options['query'] ?? null,
            ], fn ($v) => $v !== null));
        } catch (ConnectionException) {
            $this->log($type, $method, $parts, null, $started, 'connection failed or timed out');
            throw new ProviderCallUncertain('The provider could not be reached or did not answer in time.');
        }

        $json = $response->json();
        $result = new ProviderHttpResponse($response->status(), is_array($json) ? $json : null);
        if (! $result->successful() || $result->json === null) {
            $this->log($type, $method, $parts, $result->status, $started, $result->json === null ? 'response was not JSON' : self::redact($result->json));
        }

        return $result;
    }

    /**
     * The one rule for where an adapter may send requests: https, no login
     * or port in the URL, and a host the adapter declares (case-insensitive).
     * Also used by the registry to check a provider's configured base URL.
     *
     * @param  list<string>  $allowedHosts
     */
    public static function allows(string $url, array $allowedHosts): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');

        return ($parts['scheme'] ?? null) === 'https' && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
            && $host !== '' && in_array($host, array_map('strtolower', $allowedHosts), true);
    }

    /** The adapter's timeout clamped to the configured range. */
    public static function timeout(int $seconds): int
    {
        $config = config('providers.http');

        return max($config['min_timeout'], min($config['max_timeout'], $seconds > 0 ? $seconds : $config['default_timeout']));
    }

    /**
     * Recursively masks values whose keys look sensitive (credentials, contact
     * details, identity and identity-result data), omits string values longer
     * than 200 characters, and masks long digit runs in any other value,
     * whether sent as text or as a JSON number.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            } elseif (is_string($value)) {
                $data[$key] = mb_strlen($value) > self::MAX_LOGGED_LENGTH ? '[omitted]' : self::maskDigits($value);
            } elseif ((is_int($value) || is_float($value)) && self::maskDigits((string) $value) !== (string) $value) {
                $data[$key] = self::maskDigits((string) $value); // a long number sent as a JSON number (e.g. an 11-digit NIN)
            }
        }

        return $data;
    }

    public static function maskDigits(string $value): string
    {
        return preg_replace('/\d{7,}/', '[number]', $value);
    }

    /** @param  array<string, mixed>  $parts */
    private function log(ProviderCallType $type, string $method, array $parts, ?int $status, float $started, string|array $detail): void
    {
        // Host and masked path only: never the query string, headers or unredacted body values.
        Log::warning('Provider request did not return a usable response', [
            'call' => $type->value,
            'method' => strtoupper($method),
            'endpoint' => ($parts['host'] ?? '').self::maskDigits($parts['path'] ?? ''),
            'status' => $status,
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'detail' => $detail,
        ]);
    }
}
