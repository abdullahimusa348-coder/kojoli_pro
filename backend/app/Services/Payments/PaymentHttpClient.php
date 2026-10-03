<?php

namespace App\Services\Payments;

use App\Exceptions\Payments\GatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only place the payment engine talks to the outside world.
 * - https only, and only to hosts the adapter declared for the mode (no
 *   admin-supplied URLs, so no SSRF);
 * - short connect/total timeouts;
 * - retries only when the caller marks the call safe (read-only
 *   verification); initialization POSTs are never retried here;
 * - failures are logged with secrets, tokens, Authorization headers and
 *   sensitive payment fields redacted, and surface as GatewayException with a
 *   safe message.
 */
class PaymentHttpClient
{
    private const SENSITIVE = '/(authori[sz]ation|secret|token|password|passcode|api[_-]?key|private|signature|pin|cvv|cvc|card|pan|expiry|account[_-]?number|bvn|nin)/i';

    /**
     * @param  list<string>  $allowedHosts
     * @param  array<string, mixed>  $options  headers, json, query, basic_auth [user, pass]
     * @return array<string, mixed> decoded JSON object
     *
     * @throws GatewayException
     */
    public function send(string $method, string $url, array $allowedHosts, array $options = [], bool $retryable = false): array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ! in_array($parts['host'] ?? '', $allowedHosts, true)) {
            throw new GatewayException('Refused to call a host the gateway adapter has not declared.');
        }

        $config = config('payments.http');
        $request = Http::connectTimeout($config['connect_timeout'])->timeout($config['timeout'])
            ->acceptJson()->withHeaders($options['headers'] ?? []);
        if (isset($options['basic_auth'])) {
            $request = $request->withBasicAuth(...$options['basic_auth']);
        }
        if ($retryable) {
            $request = $request->retry($config['verify_retries'] + 1, $config['retry_sleep_ms'],
                fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), throw: false);
        }

        $started = microtime(true);
        try {
            $response = $request->send(strtoupper($method), $url, array_filter([
                'json' => $options['json'] ?? null,
                'query' => $options['query'] ?? null,
            ], fn ($v) => $v !== null));
        } catch (ConnectionException) {
            $this->logFailure($method, $parts, null, $started, 'connection failed or timed out');
            throw new GatewayException('The payment gateway could not be reached (network error or timeout).');
        }

        if (! $response->successful()) {
            $this->logFailure($method, $parts, $response->status(), $started, self::redact((array) $response->json()));
            throw new GatewayException("The payment gateway returned HTTP {$response->status()}.");
        }

        $data = $response->json();
        if (! is_array($data)) {
            $this->logFailure($method, $parts, $response->status(), $started, 'response was not a JSON object');
            throw new GatewayException('The payment gateway returned an invalid response.');
        }

        return $data;
    }

    /**
     * Recursively masks values whose keys look sensitive.
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
            }
        }

        return $data;
    }

    /** @param  array<string, mixed>  $parts */
    private function logFailure(string $method, array $parts, ?int $status, float $started, string|array $detail): void
    {
        // Host and path only: no query string, headers or body values that were not redacted.
        Log::warning('Payment gateway request failed', [
            'method' => strtoupper($method),
            'endpoint' => ($parts['host'] ?? '').($parts['path'] ?? ''),
            'status' => $status,
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'detail' => $detail,
        ]);
    }
}
