<?php

use App\Models\PaymentWebhook;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Support\Payments\GatewayStatus;
use App\Support\Payments\PaymentStatus;
use App\Support\Payments\WebhookOutcome;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\Payments\FakeGateway;

require_once __DIR__.'/../../Support/Payments/helpers.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SettingsSeeder::class);
    payDrivers();
    Http::preventStrayRequests();
});

it('verifies with the gateway and credits once for a valid webhook', function () {
    $gateway = payGateway();
    $payment = payStarted(gateway: $gateway);
    FakeGateway::pay($payment->reference);

    payWebhook($this, $gateway, ['event_id' => 'evt-1', 'event' => 'paid', 'reference' => $payment->reference])
        ->assertOk()->assertExactJson(['received' => true]);

    $webhook = PaymentWebhook::sole();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Successful)
        ->and(Wallet::find($payment->wallet_id)->balance_kobo)->toBe(250_000)
        ->and($webhook->outcome)->toBe(WebhookOutcome::Processed)
        ->and($webhook->payment_id)->toBe($payment->id)
        ->and($webhook->signature_valid)->toBeTrue()
        ->and($webhook->processed_at)->not->toBeNull()
        ->and(FakeGateway::calls('verify'))->toBe(1);
});

it('never trusts the webhook claim: no credit while the gateway says unpaid', function () {
    $gateway = payGateway();
    $payment = payStarted(gateway: $gateway);

    payWebhook($this, $gateway, ['event_id' => 'evt-1', 'event' => 'paid', 'status' => 'successful', 'amount' => 250_000, 'reference' => $payment->reference])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)->and(Transaction::count())->toBe(0);
});

it('processes a redelivered event only once', function () {
    $gateway = payGateway();
    $payment = payStarted(gateway: $gateway);
    FakeGateway::pay($payment->reference);

    foreach (range(1, 5) as $i) {
        payWebhook($this, $gateway, ['event_id' => 'evt-same', 'reference' => $payment->reference])->assertOk();
    }

    expect(PaymentWebhook::count())->toBe(1)
        ->and(FakeGateway::calls('verify'))->toBe(1)
        ->and(Transaction::where('idempotency_key', 'payment:'.$payment->reference)->count())->toBe(1)
        ->and(Wallet::find($payment->wallet_id)->balance_kobo)->toBe(250_000);
});

it('credits once even for different events about the same payment', function () {
    $gateway = payGateway();
    $payment = payStarted(gateway: $gateway);
    FakeGateway::pay($payment->reference);

    foreach (['evt-a', 'evt-b', 'evt-c'] as $event) {
        payWebhook($this, $gateway, ['event_id' => $event, 'gateway_reference' => $payment->gateway_reference, 'reference' => 'ignored'])->assertOk();
    }

    expect(PaymentWebhook::count())->toBe(3)
        ->and(Transaction::where('idempotency_key', 'payment:'.$payment->reference)->count())->toBe(1)
        ->and(Wallet::find($payment->wallet_id)->balance_kobo)->toBe(250_000)
        ->and(Artisan::call('wallet:verify'))->toBe(0);
});

it('rejects invalid or missing signatures without storing the payload or crediting', function (?string $secret) {
    $gateway = payGateway();
    $payment = payStarted(gateway: $gateway);
    FakeGateway::pay($payment->reference);

    payWebhook($this, $gateway, ['event_id' => 'evt-1', 'reference' => $payment->reference], $secret)->assertStatus(401);

    $webhook = PaymentWebhook::sole();
    expect($webhook->outcome)->toBe(WebhookOutcome::InvalidSignature)
        ->and($webhook->signature_valid)->toBeFalse()
        ->and($webhook->payload)->toBeNull()
        ->and($webhook->payment_id)->toBeNull()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(FakeGateway::calls('verify'))->toBe(0);
})->with(['wrong secret' => 'wrong-secret', 'unsigned' => null]);

it('records malformed bodies without processing them', function (string $body) {
    $gateway = payGateway();
    $headers = $this->transformHeadersToServerVars(['Content-Type' => 'application/json', 'X-Fake-Signature' => FakeGateway::sign($body, PAY_WEBHOOK_SECRET)]);

    $this->call('POST', '/api/webhooks/payments/'.$gateway->code, [], [], [], $headers, $body)->assertStatus(400);

    expect(PaymentWebhook::sole()->outcome)->toBe(WebhookOutcome::Malformed)->and(PaymentWebhook::sole()->payload)->toBeNull();
})->with(['not json' => 'not json', 'no event id' => '{"reference":"PAY-X"}', 'array' => '[1,2]']);

it('records events for unknown payments and never processes them', function () {
    $gateway = payGateway();
    $other = payGateway();
    $foreign = payStarted(gateway: $other); // belongs to another gateway
    FakeGateway::pay($foreign->reference);

    payWebhook($this, $gateway, ['event_id' => 'evt-1', 'reference' => 'PAY-01J00000000000000000000000'])->assertOk();
    payWebhook($this, $gateway, ['event_id' => 'evt-2', 'reference' => $foreign->reference])->assertOk();

    expect(PaymentWebhook::pluck('outcome')->map->value->all())->toBe(['unknown_payment', 'unknown_payment'])
        ->and($foreign->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('answers 404 for unknown gateways and 503 for unconfigured ones', function () {
    $this->postJson('/api/webhooks/payments/no-such-gateway', ['event_id' => 'x'])->assertNotFound();
    expect(PaymentWebhook::count())->toBe(0);

    $gateway = payGateway([], []);
    payWebhook($this, $gateway, ['event_id' => 'evt-1', 'reference' => 'PAY-X'])->assertStatus(503);
    expect(PaymentWebhook::sole()->outcome)->toBe(WebhookOutcome::NotConfigured);
});

it('still accepts webhooks for a gateway taken out of service, so paid payments are settled', function () {
    $gateway = payGateway();
    $payment = payStarted(gateway: $gateway);
    FakeGateway::pay($payment->reference);
    $gateway->forceFill(['status' => GatewayStatus::Inactive])->save();

    payWebhook($this, $gateway, ['event_id' => 'evt-1', 'reference' => $payment->reference])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Successful);
});

it('rejects bodies over 64 KB unread', function () {
    $gateway = payGateway();
    $body = json_encode(['event_id' => 'evt-1', 'pad' => str_repeat('a', 70_000)]);
    $headers = $this->transformHeadersToServerVars(['Content-Type' => 'application/json', 'X-Fake-Signature' => FakeGateway::sign($body, PAY_WEBHOOK_SECRET)]);

    $this->call('POST', '/api/webhooks/payments/'.$gateway->code, [], [], [], $headers, $body)->assertStatus(413);
    expect(PaymentWebhook::count())->toBe(0);
});

it('stores only the allow-listed payload: no headers, secrets or card data', function () {
    $gateway = payGateway();
    $payment = payStarted(gateway: $gateway);

    payWebhook($this, $gateway, ['event_id' => 'evt-1', 'event' => 'paid', 'reference' => $payment->reference,
        'card_number' => '4111111111111111', 'cvv' => '123', 'secret' => 'leak', 'customer' => ['bvn' => '22222222222']])->assertOk();

    $raw = json_encode(PaymentWebhook::sole()->getAttributes());
    expect(PaymentWebhook::sole()->payload)->toBe(['event_id' => 'evt-1', 'event' => 'paid', 'reference' => $payment->reference]);
    foreach (['4111111111111111', '"cvv"', 'leak', '22222222222', PAY_WEBHOOK_SECRET, 'X-Fake-Signature', 'x-fake-signature'] as $needle) {
        expect(str_contains($raw, $needle))->toBeFalse("stored {$needle}");
    }
});

it('needs no session or CSRF token and sets no cookies', function () {
    $gateway = payGateway();
    $payment = payStarted(gateway: $gateway);

    $response = payWebhook($this, $gateway, ['event_id' => 'evt-1', 'reference' => $payment->reference])->assertOk();

    expect($response->headers->getCookies())->toBe([]);
});

it('keeps webhook rows append-only and prunes only old payloads', function () {
    $gateway = payGateway();
    $payment = payStarted(gateway: $gateway);
    payWebhook($this, $gateway, ['event_id' => 'evt-old', 'reference' => $payment->reference]);
    $this->travel(181)->days();
    payWebhook($this, $gateway, ['event_id' => 'evt-new', 'reference' => $payment->reference]);
    $webhook = PaymentWebhook::firstWhere('event_key', 'evt-old');

    expect(fn () => $webhook->forceFill(['event_key' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $webhook->forceFill(['payload' => ['x' => 1]])->save())->toThrow(LogicException::class)
        ->and(fn () => $webhook->delete())->toThrow(LogicException::class);

    Artisan::call('payments:prune-webhooks');

    expect(PaymentWebhook::firstWhere('event_key', 'evt-old')->payload)->toBeNull()
        ->and(PaymentWebhook::firstWhere('event_key', 'evt-old')->outcome)->toBe(WebhookOutcome::Processed)
        ->and(PaymentWebhook::firstWhere('event_key', 'evt-new')->payload)->not->toBeNull()
        ->and(PaymentWebhook::count())->toBe(2);
});
