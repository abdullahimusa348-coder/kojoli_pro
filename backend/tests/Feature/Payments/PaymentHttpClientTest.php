<?php

use App\Exceptions\Payments\GatewayException;
use App\Services\Payments\PaymentHttpClient;
use App\Support\Payments\PaymentSource;
use App\Support\Payments\PaymentStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\Payments\HttpTestGateway;

require_once __DIR__.'/../../Support/Payments/helpers.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SettingsSeeder::class);
    payDrivers();
    config(['payments.http.retry_sleep_ms' => 0]);
    Http::preventStrayRequests();
});

function payHttpGateway()
{
    return payGateway(['driver' => 'http-test']);
}

function payHttpCreate()
{
    return payService()->create(payCustomer(), 250_000, payHttpGateway(), (string) Str::uuid());
}

it('initializes over https with the bearer credential and short timeouts', function () {
    Http::fake([HttpTestGateway::API.'/init' => Http::response(['checkout_url' => 'https://pay.http-gateway.test/c/1', 'gateway_reference' => 'G-1'])]);

    $payment = payService()->initialize(payHttpCreate(), 'https://nadabo.test/return');

    expect($payment->checkout_url)->toBe('https://pay.http-gateway.test/c/1')->and($payment->gateway_reference)->toBe('G-1');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer '.PAY_API_KEY)
        && $r['reference'] === $payment->reference && $r['amount'] === 250_000);
    Http::assertSentCount(1);
});

it('never retries an initialization POST, and fails the payment safely', function (Closure $response) {
    $calls = 0;
    Http::fake([HttpTestGateway::API.'/init' => function () use (&$calls, $response) {
        $calls++;

        return $response();
    }]);

    $payment = payService()->initialize(payHttpCreate(), 'https://nadabo.test/return');

    expect($payment->status)->toBe(PaymentStatus::Failed)->and($payment->checkout_url)->toBeNull()->and($calls)->toBe(1);
})->with([
    'server error' => fn () => Http::response(['error' => 'down'], 500),
    'rejected' => fn () => Http::response(['error' => 'bad'], 400),
    'timeout' => fn () => throw new ConnectionException('timed out'),
    'not json' => fn () => Http::response('<html>oops</html>', 200),
    'missing fields' => fn () => Http::response(['ok' => true], 200),
]);

it('retries initialization only when the adapter declares it idempotent', function () {
    HttpTestGateway::$idempotentInit = true;
    Http::fakeSequence(HttpTestGateway::API.'/init')
        ->push(['error' => 'down'], 503)
        ->push(['checkout_url' => 'https://pay.http-gateway.test/c/2', 'gateway_reference' => 'G-2']);

    $payment = payService()->initialize(payHttpCreate(), 'https://nadabo.test/return');

    expect($payment->status)->toBe(PaymentStatus::Pending)->and($payment->gateway_reference)->toBe('G-2');
    Http::assertSentCount(2);
});

describe('verification over HTTP', function () {
    function payHttpStarted()
    {
        Http::fake([HttpTestGateway::API.'/init' => Http::response(['checkout_url' => 'https://pay.http-gateway.test/c/1', 'gateway_reference' => 'G-1'])]);

        return payService()->initialize(payHttpCreate(), 'https://nadabo.test/return');
    }

    it('maps paid, pending and failed answers', function (array $answer, PaymentStatus $expected) {
        $payment = payHttpStarted();
        Http::fake([HttpTestGateway::API.'/verify/*' => Http::response($answer)]);

        expect(payService()->verifyAndFinalize($payment, PaymentSource::Return)->status)->toBe($expected);
    })->with([
        'paid exact' => [['status' => 'paid', 'amount' => 250_000, 'currency' => 'NGN', 'gateway_reference' => 'G-1'], PaymentStatus::Successful],
        'paid wrong amount' => [['status' => 'paid', 'amount' => 25_000, 'currency' => 'NGN'], PaymentStatus::Review],
        'paid other reference' => [['status' => 'paid', 'amount' => 250_000, 'currency' => 'NGN', 'gateway_reference' => 'G-9'], PaymentStatus::Review],
        'pending' => [['status' => 'pending'], PaymentStatus::Pending],
        'failed' => [['status' => 'failed'], PaymentStatus::Failed],
    ]);

    it('retries safe verification calls on network errors and 5xx, then gives up without changes', function () {
        $payment = payHttpStarted();
        Http::fakeSequence(HttpTestGateway::API.'/verify/*')
            ->pushFailedConnection()->push(['error' => 'busy'], 502)
            ->push(['status' => 'paid', 'amount' => 250_000, 'currency' => 'NGN']);

        expect(payService()->verifyAndFinalize($payment, PaymentSource::Return)->status)->toBe(PaymentStatus::Successful);
    });

    it('gives up after the allowed verification retries without changing the payment', function () {
        $payment = payHttpStarted();
        $calls = 0;
        Http::fake([HttpTestGateway::API.'/verify/*' => function () use (&$calls) {
            $calls++;

            return Http::response(['error' => 'down'], 500);
        }]);

        expect(fn () => payService()->verifyAndFinalize($payment, PaymentSource::Return))->toThrow(GatewayException::class);
        expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)->and($calls)->toBe(3); // 1 + verify_retries (2)
    });

    it('treats malformed verification answers as unavailable, never as paid', function (Closure $response) {
        $payment = payHttpStarted();
        Http::fake([HttpTestGateway::API.'/verify/*' => $response]);

        expect(fn () => payService()->verifyAndFinalize($payment, PaymentSource::Return))->toThrow(GatewayException::class);
        expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    })->with([
        'not json' => fn () => Http::response('garbage', 200),
        'unknown status' => fn () => Http::response(['status' => 'maybe'], 200),
        'timeout' => fn () => throw new ConnectionException('timed out'),
    ]);
});

it('refuses hosts the adapter did not declare and plain http', function (string $url) {
    expect(fn () => app(PaymentHttpClient::class)->send('GET', $url, ['api.http-gateway.test']))->toThrow(GatewayException::class);
    Http::assertNothingSent();
})->with([
    'other host' => 'https://169.254.169.254/latest/meta-data',
    'http' => 'http://api.http-gateway.test/verify/x',
    'lookalike' => 'https://api.http-gateway.test.evil.example/x',
    'localhost' => 'https://localhost/x',
]);

it('logs failures with secrets, tokens and card data redacted', function () {
    Log::spy();
    Http::fake(['*' => Http::response(['error' => 'bad', 'secret_key' => 'leak-1', 'access_token' => 'leak-2', 'card' => ['pan' => '4111111111111111'], 'note' => 'ok'], 400)]);

    try {
        app(PaymentHttpClient::class)->send('POST', 'https://api.http-gateway.test/init?apikey=leak-3', ['api.http-gateway.test'],
            ['headers' => ['Authorization' => 'Bearer '.PAY_API_KEY], 'json' => ['pin' => '0000']]);
    } catch (GatewayException $e) {
        expect($e->getMessage())->toBe('The payment gateway returned HTTP 400.');
    }

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
        $logged = json_encode($context);
        foreach (['leak-1', 'leak-2', 'leak-3', '4111111111111111', PAY_API_KEY, '0000', 'Bearer'] as $secret) {
            if (str_contains($logged, $secret)) {
                return false;
            }
        }

        return $context['endpoint'] === 'api.http-gateway.test/init' && $context['status'] === 400 && $context['detail']['note'] === 'ok';
    });
});

it('redacts nested sensitive keys', function () {
    expect(PaymentHttpClient::redact(['Authorization' => 'x', 'data' => ['accountNumber' => '1', 'account_number' => '2', 'bvn' => '3', 'amount' => 5]]))
        ->toBe(['Authorization' => '[redacted]', 'data' => ['accountNumber' => '[redacted]', 'account_number' => '[redacted]', 'bvn' => '[redacted]', 'amount' => 5]]);
});
