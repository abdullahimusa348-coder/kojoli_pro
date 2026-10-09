<?php

use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PaymentGatewayCredential;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Settings\SettingsStore;
use App\Support\Enums\SystemRole;
use App\Support\Payments\GatewayMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\Payments\FakeGateway;
use Tests\Support\Payments\HttpTestGateway;

/*
 * Shared helpers for the Phase 9 payment tests. Credential values here are
 * obviously fake test strings; no real gateway credentials exist anywhere.
 */

const PAY_API_KEY = 'fake-api-key-NOT-REAL-91ab';
const PAY_WEBHOOK_SECRET = 'fake-webhook-secret-NOT-REAL-4d2f';

/** Registers the test-only adapters (production ships with none) and resets the fake gateway side. */
function payDrivers(): void
{
    config(['payments.drivers' => ['fake' => FakeGateway::class, 'http-test' => HttpTestGateway::class]]);
    FakeGateway::reset();
    HttpTestGateway::$idempotentInit = false;
    Cache::flush();
}

/** An active fake gateway with credentials for the given modes. */
function payGateway(array $attributes = [], array $credentialModes = [GatewayMode::Sandbox]): PaymentGateway
{
    $gateway = PaymentGateway::factory()->create($attributes);
    $keys = $gateway->driver === 'http-test' ? ['secret_key' => PAY_API_KEY] : ['api_key' => PAY_API_KEY, 'webhook_secret' => PAY_WEBHOOK_SECRET];
    foreach ($credentialModes as $mode) {
        foreach ($keys as $key => $value) {
            (new PaymentGatewayCredential)->forceFill(['payment_gateway_id' => $gateway->id, 'mode' => $mode, 'key' => $key,
                'value' => $value, 'hint' => PaymentGatewayCredential::hintFor($value)])->save();
        }
    }

    return $gateway->fresh();
}

function payService(): PaymentService
{
    return app(PaymentService::class);
}

function payCustomer(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

/** A pending payment whose checkout has started with the gateway. */
function payStarted(?User $user = null, int $amountKobo = 250_000, ?PaymentGateway $gateway = null): Payment
{
    $payment = payService()->create($user ?? payCustomer(), $amountKobo, $gateway ?? payGateway(), (string) Str::uuid());

    return payService()->initialize($payment, 'https://nadabo.test/wallet/fund/'.$payment->reference);
}

function payStaff(SystemRole|string $role = SystemRole::SuperAdmin): SystemUser
{
    $staff = SystemUser::factory()->create();
    $staff->assignRole($role instanceof SystemRole ? $role->value : $role);

    return $staff;
}

/** Staff with a custom role holding exactly the given permissions (plus admin.access). */
function payRole(array $permissions): SystemUser
{
    $role = Role::create(['name' => 'PAY '.implode(' ', $permissions).' '.Str::random(4), 'guard_name' => 'admin'])
        ->givePermissionTo(['admin.access', ...$permissions]);

    return payStaff($role->name);
}

function paySetting(string $key, mixed $value): void
{
    app(SettingsStore::class)->set($key, $value);
}

/** Posts a signed fake-gateway webhook. */
function payWebhook($test, PaymentGateway $gateway, array $data, ?string $secret = PAY_WEBHOOK_SECRET)
{
    $body = FakeGateway::body($data);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($secret !== null) {
        $server['HTTP_X_FAKE_SIGNATURE'] = FakeGateway::sign($body, $secret);
    }

    return $test->call('POST', '/api/webhooks/payments/'.$gateway->code, [], [], [], $server, $body);
}
