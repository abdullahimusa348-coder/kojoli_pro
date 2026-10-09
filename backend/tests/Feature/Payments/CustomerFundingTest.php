<?php

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Support\Payments\GatewayMode;
use App\Support\Payments\GatewayStatus;
use App\Support\Payments\PaymentStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Payments\FakeGateway;

require_once __DIR__.'/../../Support/Payments/helpers.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SettingsSeeder::class);
    payDrivers();
    Http::preventStrayRequests();
});

function payFundForm(array $overrides = []): array
{
    return $overrides + ['amount' => '2500', 'idempotency_key' => (string) Str::uuid()];
}

it('offers funding only while a configured gateway is active', function () {
    $customer = payCustomer();
    $this->actingAs($customer)->get('/wallet')->assertOk()->assertDontSee('data-fund-wallet', false);
    $this->get('/wallet/fund')->assertOk()->assertSee('data-fund-unavailable', false)->assertDontSee('data-fund-form', false);

    $gateway = payGateway(['name' => 'Card & Transfer']);
    $this->get('/wallet')->assertSee('data-fund-wallet', false)->assertSee('Fund wallet');
    $this->get('/wallet/fund')->assertSee('data-fund-form', false)->assertSee('Card &amp; Transfer', false)
        ->assertSee('From ₦100.00 to ₦500,000.00');

    $gateway->forceFill(['status' => GatewayStatus::Maintenance])->save();
    $this->get('/wallet')->assertDontSee('data-fund-wallet', false);
});

it('never offers a live gateway while live payments are off', function () {
    payGateway(['mode' => GatewayMode::Live], [GatewayMode::Live]);

    $this->actingAs(payCustomer())->get('/wallet/fund')->assertSee('data-fund-unavailable', false);

    paySetting('payments.live_enabled', true);
    $this->get('/wallet/fund')->assertSee('data-fund-form', false);
});

it('creates a payment and redirects to the gateway checkout without crediting', function () {
    $gateway = payGateway();
    $customer = payCustomer();

    $response = $this->actingAs($customer)->post('/wallet/fund', payFundForm(['amount' => '1,250.50', 'gateway' => $gateway->id]));

    $payment = Payment::sole();
    $response->assertRedirect($payment->checkout_url);
    expect($payment->checkout_url)->toStartWith('https://'.FakeGateway::CHECKOUT_HOST.'/')
        ->and($payment->amount_kobo)->toBe(125_050)
        ->and($payment->user_id)->toBe($customer->id)
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and(Transaction::count())->toBe(0)
        ->and(Wallet::where('user_id', $customer->id)->first()->balance_kobo)->toBe(0);
});

it('validates the amount and the gateway', function (array $input, string $field) {
    payGateway();

    $this->actingAs(payCustomer())->from('/wallet/fund')->post('/wallet/fund', payFundForm($input + ['gateway' => payGateway()->id]))
        ->assertRedirect('/wallet/fund')->assertSessionHasErrors($field);

    expect(Payment::count())->toBe(0);
})->with([
    'blank' => [['amount' => ''], 'amount'],
    'letters' => [['amount' => 'abc'], 'amount'],
    'negative' => [['amount' => '-100'], 'amount'],
    'three decimals' => [['amount' => '100.123'], 'amount'],
    'below minimum' => [['amount' => '99.99'], 'amount'],
    'above maximum' => [['amount' => '500000.01'], 'amount'],
    'unknown gateway' => [['gateway' => 999999], 'gateway'],
    'missing key' => [['idempotency_key' => ''], 'idempotency_key'],
]);

it('refuses a gateway that is not usable', function () {
    $inactive = payGateway(['status' => GatewayStatus::Inactive]);
    payGateway();

    $this->actingAs(payCustomer())->post('/wallet/fund', payFundForm(['gateway' => $inactive->id]))->assertSessionHasErrors('gateway');
    expect(Payment::count())->toBe(0);
});

it('creates one payment and one checkout for a double-submitted form', function () {
    $gateway = payGateway();
    $form = payFundForm(['gateway' => $gateway->id]);
    $this->actingAs(payCustomer());

    $first = $this->post('/wallet/fund', $form);
    $second = $this->post('/wallet/fund', $form);

    expect(Payment::count())->toBe(1)->and(FakeGateway::calls('initialize'))->toBe(1);
    $first->assertRedirect(Payment::sole()->checkout_url);
    $second->assertRedirect(Payment::sole()->checkout_url);
});

it('shows a failed status page, not a redirect, when checkout cannot start', function () {
    FakeGateway::$failInitialize = true;
    $gateway = payGateway();

    $response = $this->actingAs(payCustomer())->post('/wallet/fund', payFundForm(['gateway' => $gateway->id]));

    $payment = Payment::sole();
    $response->assertRedirect(route('wallet.fund.show', $payment->reference));
    $this->get(route('wallet.fund.show', $payment->reference))->assertOk()->assertSee('data-payment-result="failed"', false)
        ->assertSee('Payment not completed');
});

describe('return / status page', function () {
    it('does not credit on return while the gateway has no confirmation', function () {
        $customer = payCustomer();
        $payment = payStarted($customer);

        $this->actingAs($customer)->get(route('wallet.fund.show', $payment->reference).'?status=success&paid=1')
            ->assertOk()->assertSee('data-payment-result="pending"', false)->assertSee('Waiting for confirmation')
            ->assertDontSee('Payment received');
        expect(Transaction::count())->toBe(0)->and(FakeGateway::calls('verify'))->toBe(1);
    });

    it('verifies server-side on return and credits once', function () {
        $customer = payCustomer();
        $payment = payStarted($customer, 300_000);
        FakeGateway::pay($payment->reference);

        $this->actingAs($customer)->get(route('wallet.fund.show', $payment->reference))
            ->assertOk()->assertSee('data-payment-result="successful"', false)->assertSee('Your wallet has been credited.');
        $this->get(route('wallet.fund.show', $payment->reference))->assertOk();

        expect(Transaction::where('idempotency_key', 'payment:'.$payment->reference)->count())->toBe(1)
            ->and(Wallet::find($payment->wallet_id)->balance_kobo)->toBe(300_000)
            ->and(FakeGateway::calls('verify'))->toBe(1);
        $this->get('/wallet')->assertSee('₦3,000.00')->assertSee('Wallet funding');
    });

    it('stays pending with a notice when the gateway cannot be reached', function () {
        $customer = payCustomer();
        $payment = payStarted($customer);
        FakeGateway::$failVerify = true;

        $this->actingAs($customer)->get(route('wallet.fund.show', $payment->reference))
            ->assertOk()->assertSee('data-check-unavailable', false)->assertSee('data-payment-result="pending"', false);
    });

    it('shows review without exposing internal reasons', function () {
        $customer = payCustomer();
        $payment = payStarted($customer);
        FakeGateway::pay($payment->reference, 1);

        $this->actingAs($customer)->get(route('wallet.fund.show', $payment->reference))
            ->assertSee('data-payment-result="review"', false)->assertDontSee('mismatch');
    });

    it('shows customers only their own payments', function () {
        $ada = payCustomer();
        $bola = payCustomer();
        $adaPayment = payStarted($ada);
        FakeGateway::pay($adaPayment->reference);

        $this->actingAs($bola)->get(route('wallet.fund.show', $adaPayment->reference))->assertNotFound();
        $this->get('/wallet/fund')->assertDontSee($adaPayment->reference);
        expect($adaPayment->fresh()->status)->toBe(PaymentStatus::Pending); // Bola's request verified nothing

        $this->actingAs($ada)->get('/wallet/fund')->assertSee($adaPayment->reference);
    });

    it('requires sign-in and a well-formed reference', function () {
        $payment = payStarted();

        $this->get(route('wallet.fund.show', $payment->reference))->assertRedirect(route('login'));
        $this->get('/wallet/fund')->assertRedirect(route('login'));
        $this->actingAs(payCustomer())->get('/wallet/fund/PAY-nope')->assertNotFound();
    });
});

it('throttles funding requests and status checks', function () {
    $store = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'wallet.fund.store');
    $show = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'wallet.fund.show');

    expect($store->gatherMiddleware())->toContain('throttle:10,1')->and($show->gatherMiddleware())->toContain('throttle:30,1');
});

it('offers no withdrawal, transfer or purchase actions and no fake success', function () {
    payGateway();
    $customer = payCustomer();
    $payment = payStarted($customer);

    foreach (['/wallet', '/wallet/fund', route('wallet.fund.show', $payment->reference)] as $url) {
        $html = mb_strtolower($this->actingAs($customer)->get($url)->getContent());
        foreach (['withdraw', 'transfer', 'buy ', 'purchase', 'coming soon', 'payment received', 'has been credited'] as $word) {
            expect(str_contains($html, $word))->toBeFalse("found \"{$word}\" on {$url}");
        }
    }
});
