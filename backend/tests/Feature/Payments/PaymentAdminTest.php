<?php

use App\Actions\Admin\Payments\ClosePaymentReview;
use App\Actions\Admin\Payments\RecheckPayment;
use App\Actions\Admin\Payments\SaveGateway;
use App\Actions\Admin\Payments\SaveGatewayCredentials;
use App\Actions\Admin\Payments\SetGatewayMode;
use App\Actions\Admin\Payments\SetGatewayStatus;
use App\Exceptions\Payments\PaymentException;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PaymentGatewayCredential;
use App\Models\PaymentGatewayCredentialChange;
use App\Models\Transaction;
use App\Services\Payments\GatewayRegistry;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Payments\GatewayMode;
use App\Support\Payments\GatewayStatus;
use App\Support\Payments\PaymentLimits;
use App\Support\Payments\PaymentSource;
use App\Support\Payments\PaymentStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Payments\FakeGateway;
use Tests\Support\Payments\HttpTestGateway;

require_once __DIR__.'/../../Support/Payments/helpers.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SettingsSeeder::class);
    payDrivers();
    Http::preventStrayRequests();
});

describe('permissions and navigation', function () {
    it('adds the gateway and credential permissions to the Payments module, Super Admin only by default', function () {
        expect(SystemPermission::PaymentsGateways->value)->toBe('payments.gateways')
            ->and(SystemPermission::PaymentsCredentials->value)->toBe('payments.credentials');

        foreach (SystemRole::cases() as $role) {
            $staff = payStaff($role);
            foreach (['payments.view', 'payments.manage', 'payments.gateways', 'payments.credentials'] as $permission) {
                expect($staff->can($permission))->toBe($role === SystemRole::SuperAdmin, "{$role->value} {$permission}");
            }
        }

        $this->actingAs(payStaff(), 'admin')->get('/admin/roles/create')->assertOk()
            ->assertSee('value="payments.gateways"', false)->assertSee('value="payments.credentials"', false);
    });

    it('builds the Payments module (no placeholder) for staff with payments.view', function () {
        $this->actingAs(payStaff(), 'admin')->get('/admin/payments')->assertOk()
            ->assertDontSee('is not built yet')->assertSee('data-payments-tab="gateways"', false);
        $this->get('/admin')->assertSee('data-nav="payments"', false);
    });

    it('denies staff without payments.view', function () {
        $gateway = payGateway();
        $payment = payStarted(gateway: $gateway);
        $this->actingAs(payStaff(SystemRole::Finance), 'admin');

        foreach (['/admin/payments', '/admin/payments/gateways', "/admin/payments/{$payment->id}", "/admin/payments/gateways/{$gateway->id}"] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post("/admin/payments/{$payment->id}/recheck")->assertForbidden();
    });

    it('lets view-only staff look but not change anything', function () {
        $gateway = payGateway();
        $payment = payStarted(gateway: $gateway);
        $this->actingAs(payRole(['payments.view']), 'admin');

        $this->get("/admin/payments/{$payment->id}")->assertOk()->assertDontSee('data-recheck', false);
        $this->get("/admin/payments/gateways/{$gateway->id}")->assertOk()->assertDontSee('data-credentials-form', false)
            ->assertDontSee('data-gateway-edit', false)->assertDontSee('data-set-status', false);
        $this->get('/admin/payments/gateways')->assertOk()->assertDontSee('Add gateway');

        $this->post("/admin/payments/{$payment->id}/recheck")->assertForbidden();
        $this->post("/admin/payments/{$payment->id}/close-review", ['note' => 'Nope nope'])->assertForbidden();
        $this->get('/admin/payments/gateways/create')->assertForbidden();
        $this->post('/admin/payments/gateways', ['name' => 'X', 'code' => 'x', 'driver' => 'fake'])->assertForbidden();
        $this->patch("/admin/payments/gateways/{$gateway->id}/status", ['status' => 'inactive'])->assertForbidden();
        $this->patch("/admin/payments/gateways/{$gateway->id}/mode", ['mode' => 'sandbox'])->assertForbidden();
        $this->put("/admin/payments/gateways/{$gateway->id}/credentials/sandbox", ['credentials' => ['api_key' => 'x']])->assertForbidden();
        $this->patch("/admin/payments/gateways/{$gateway->id}/credentials/sandbox/api_key/clear")->assertForbidden();
    });

    it('re-checks permissions inside every action', function (Closure $call) {
        $gateway = payGateway();
        $payment = payStarted(gateway: $gateway);
        $viewer = payRole(['payments.view']);

        expect(fn () => $call($gateway, $payment, $viewer))->toThrow(AuthorizationException::class);
    })->with([
        'recheck' => fn ($g, $p, $s) => app(RecheckPayment::class)->handle($p, $s),
        'close review' => fn ($g, $p, $s) => app(ClosePaymentReview::class)->handle($p, 'note here', $s),
        'create gateway' => fn ($g, $p, $s) => app(SaveGateway::class)->create(['name' => 'X', 'code' => 'x', 'driver' => 'fake'], $s),
        'move' => fn ($g, $p, $s) => app(SaveGateway::class)->move($g, 'up', $s),
        'status' => fn ($g, $p, $s) => app(SetGatewayStatus::class)->handle($g, GatewayStatus::Inactive, $s),
        'mode' => fn ($g, $p, $s) => app(SetGatewayMode::class)->handle($g, GatewayMode::Sandbox, $s),
        'credentials' => fn ($g, $p, $s) => app(SaveGatewayCredentials::class)->handle($g, GatewayMode::Sandbox, ['api_key' => 'x'], $s),
    ]);

    it('refuses actions by deactivated staff even with the permission', function () {
        $staff = payStaff();
        $staff->forceFill(['status' => 'disabled'])->save();

        expect(fn () => app(RecheckPayment::class)->handle(payStarted(), $staff))->toThrow(AuthorizationException::class);
    });
});

describe('payments list and detail', function () {
    it('lists, searches and filters payments', function () {
        $gateway = payGateway(['name' => 'Alpha Pay']);
        $ada = payCustomer(['name' => 'Ada Payer', 'email' => 'ada@example.test']);
        $paid = payStarted($ada, 100_000, $gateway);
        FakeGateway::pay($paid->reference);
        payService()->verifyAndFinalize($paid, PaymentSource::Return);
        $pending = payStarted(payCustomer(['name' => 'Bola Waiting']), 200_000, $gateway);

        $this->actingAs(payStaff(), 'admin')->get('/admin/payments')->assertOk()
            ->assertSee($paid->reference)->assertSee($pending->reference)->assertSee('Ada Payer')->assertSee('₦1,000.00');
        $this->get('/admin/payments?q=ada@')->assertSee($paid->reference)->assertDontSee($pending->reference);
        $this->get('/admin/payments?q='.$pending->gateway_reference)->assertSee($pending->reference)->assertDontSee($paid->reference);
        $this->get('/admin/payments?status=successful')->assertSee($paid->reference)->assertDontSee($pending->reference);
        $this->get('/admin/payments?gateway='.$gateway->id)->assertSee($paid->reference);
        $this->get('/admin/payments?status=refunded')->assertSessionHasErrors('status');
    });

    it('shows details, history, the funding transaction and webhooks', function () {
        $payment = payStarted();
        FakeGateway::pay($payment->reference);
        payService()->verifyAndFinalize($payment, PaymentSource::Webhook);
        $tx = Transaction::sole();

        $this->actingAs(payStaff(), 'admin')->get("/admin/payments/{$payment->id}")->assertOk()
            ->assertSee($payment->reference)->assertSee($tx->reference)->assertSee('data-status-change="successful"', false)
            ->assertSee('Gateway webhook')->assertSee('₦2,500.00')->assertDontSee('data-recheck', false);
    });

    it('rechecks with the gateway: credits only on a verified exact amount', function () {
        $payment = payStarted();
        $this->actingAs(payStaff(), 'admin');

        $this->post("/admin/payments/{$payment->id}/recheck")->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, 'no change'));
        expect(Transaction::count())->toBe(0);

        FakeGateway::$failVerify = true;
        $this->post("/admin/payments/{$payment->id}/recheck")->assertSessionHasErrors('recheck');

        FakeGateway::$failVerify = false;
        FakeGateway::pay($payment->reference);
        $this->post("/admin/payments/{$payment->id}/recheck")->assertSessionHas('status', fn ($s) => str_contains($s, 'Successful'));
        expect($payment->fresh()->status)->toBe(PaymentStatus::Successful)->and(Transaction::count())->toBe(1)
            ->and($payment->fresh()->statusChanges->last()->changed_by)->not->toBeNull();
    });

    it('shows review payments and lets staff close them as failed without credit', function () {
        $payment = payStarted();
        FakeGateway::pay($payment->reference, 1);
        payService()->verifyAndFinalize($payment, PaymentSource::Webhook);
        $this->actingAs(payStaff(), 'admin');

        $this->get('/admin/payments')->assertSee('data-review-count', false)->assertSee('1 payment need review');
        $this->get("/admin/payments/{$payment->id}")->assertSee('data-close-review', false)->assertSee('Amount mismatch');
        $this->post("/admin/payments/{$payment->id}/close-review", ['note' => ''])->assertSessionHasErrors('note');
        $this->post("/admin/payments/{$payment->id}/close-review", ['note' => 'Customer refunded by gateway'])->assertSessionHasNoErrors();

        expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)->and(Transaction::count())->toBe(0)
            ->and($payment->fresh()->statusChanges->last()->note)->toBe('Customer refunded by gateway');
        $this->post("/admin/payments/{$payment->id}/close-review", ['note' => 'Again please'])->assertSessionHasErrors('note');
    });

    it('has no mark-paid, refund, edit or delete routes for payments', function () {
        $routes = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'admin/payments') && ! str_contains($r->uri(), 'gateways'));

        expect($routes->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())->values()->sort()->values()->all())->toBe([
            'GET|HEAD admin/payments',
            'GET|HEAD admin/payments/{payment}',
            'POST admin/payments/{payment}/close-review',
            'POST admin/payments/{payment}/recheck',
        ]);
        // The Withdrawals module is only a navigation placeholder (Phase 14).
        expect(collect(Route::getRoutes())->filter(fn ($r) => preg_match('/(refund|mark-?paid|deposit|withdraw|virtual|payment-?point)/i', $r->uri())
            && $r->getActionName() !== 'App\\Http\\Controllers\\Admin\\ModulePlaceholderController')->map->uri()->values()->all())->toBe([]);
    });
});

describe('gateways', function () {
    it('creates admin gateways inactive, in sandbox, last in priority, only with installed drivers', function () {
        payGateway(['priority' => 7]);
        $this->actingAs(payStaff(), 'admin');

        $this->get('/admin/payments/gateways/create')->assertOk()->assertSee('Fake test gateway');
        $this->post('/admin/payments/gateways', ['name' => 'Main', 'code' => 'Bad Code', 'driver' => 'fake'])->assertSessionHasErrors('code');
        $this->post('/admin/payments/gateways', ['name' => 'Main', 'code' => 'main', 'driver' => 'monnify'])->assertSessionHasErrors('driver');
        $this->post('/admin/payments/gateways', ['name' => 'Main', 'code' => 'main', 'driver' => 'fake', 'wallet_funding' => '1'])->assertRedirect();

        $gateway = PaymentGateway::firstWhere('code', 'main');
        expect($gateway->status)->toBe(GatewayStatus::Inactive)->and($gateway->mode)->toBe(GatewayMode::Sandbox)
            ->and($gateway->priority)->toBe(8)->and($gateway->wallet_funding)->toBeTrue();
        $this->post('/admin/payments/gateways', ['name' => 'Again', 'code' => 'main', 'driver' => 'fake'])->assertSessionHasErrors('code');
    });

    it('cannot add a gateway when no driver is installed (production state)', function () {
        config(['payments.drivers' => []]);

        $this->actingAs(payStaff(), 'admin')->get('/admin/payments/gateways/create')->assertOk()->assertSee('data-no-drivers', false);
        $this->post('/admin/payments/gateways', ['name' => 'Main', 'code' => 'main', 'driver' => 'fake'])->assertSessionHasErrors('driver');
        expect(PaymentGateway::count())->toBe(0);
    });

    it('shows configuration status, the webhook address and no editable endpoints', function () {
        $gateway = payGateway(['code' => 'main-gw']);
        $bare = payGateway([], []);
        $this->actingAs(payStaff(), 'admin');

        $this->get('/admin/payments/gateways')->assertOk()->assertSee('Missing sandbox credentials: api_key, webhook_secret');
        $this->get("/admin/payments/gateways/{$gateway->id}")->assertOk()
            ->assertSee('data-configured="yes"', false)->assertSee(url('/api/webhooks/payments/main-gw'))
            ->assertSee(FakeGateway::CHECKOUT_HOST)->assertDontSee('base_url')->assertDontSee('name="url"', false);
        $this->get("/admin/payments/gateways/{$bare->id}")->assertSee('data-configured="no"', false);
    });

    it('edits name, wallet funding and only adapter-declared settings', function () {
        $gateway = payGateway();
        $this->actingAs(payStaff(), 'admin');

        $this->put("/admin/payments/gateways/{$gateway->id}", ['name' => 'Renamed', 'wallet_funding' => '0',
            'settings' => ['merchant_label' => 'Nadabo', 'base_url' => 'https://evil.example']])->assertSessionHasNoErrors();

        $gateway->refresh();
        expect($gateway->name)->toBe('Renamed')->and($gateway->wallet_funding)->toBeFalse()->and($gateway->settings)->toBe(['merchant_label' => 'Nadabo']);
        $this->put("/admin/payments/gateways/{$gateway->id}", ['name' => 'X', 'settings' => ['merchant_label' => str_repeat('a', 51)]])->assertSessionHasErrors('settings.merchant_label');
    });

    it('sets status and moves priority', function () {
        $first = payGateway(['priority' => 1]);
        $second = payGateway(['priority' => 2]);
        $this->actingAs(payStaff(), 'admin');

        $this->patch("/admin/payments/gateways/{$second->id}/status", ['status' => 'maintenance'])->assertSessionHasNoErrors();
        $this->patch("/admin/payments/gateways/{$second->id}/move", ['direction' => 'up'])->assertSessionHas('status', 'Gateway order updated.');
        $this->patch("/admin/payments/gateways/{$second->id}/move", ['direction' => 'up'])->assertSessionHas('status', 'This gateway is already first.');

        expect($second->fresh()->status)->toBe(GatewayStatus::Maintenance)
            ->and($second->fresh()->priority)->toBe(1)->and($first->fresh()->priority)->toBe(2);
    });

    it('switches to live only with the live switch on, all live credentials and confirmation', function () {
        $gateway = payGateway();
        $this->actingAs(payStaff(), 'admin');

        $this->patch("/admin/payments/gateways/{$gateway->id}/mode", ['mode' => 'live', 'confirm_live' => '1'])->assertSessionHasErrors('mode');
        paySetting('payments.live_enabled', true);
        $this->patch("/admin/payments/gateways/{$gateway->id}/mode", ['mode' => 'live', 'confirm_live' => '1'])
            ->assertSessionHasErrors(['mode' => 'This gateway cannot go live: Missing live credentials: api_key, webhook_secret.']);

        foreach (['api_key' => PAY_API_KEY, 'webhook_secret' => PAY_WEBHOOK_SECRET] as $key => $value) {
            app(SaveGatewayCredentials::class)->handle($gateway, GatewayMode::Live, [$key => $value], payStaff());
        }
        $this->patch("/admin/payments/gateways/{$gateway->id}/mode", ['mode' => 'live'])->assertSessionHasErrors('confirm_live');
        $this->patch("/admin/payments/gateways/{$gateway->id}/mode", ['mode' => 'live', 'confirm_live' => '1'])->assertSessionHasNoErrors();
        expect($gateway->fresh()->mode)->toBe(GatewayMode::Live);

        // Switching live payments off makes the live gateway unusable; it never falls back to sandbox.
        paySetting('payments.live_enabled', false);
        $this->get("/admin/payments/gateways/{$gateway->id}")->assertSee('Live payments are switched off');
        expect(fn () => payService()->create(payCustomer(), 100_000, $gateway->fresh(), 'k-1'))->toThrow(PaymentException::class);

        $this->patch("/admin/payments/gateways/{$gateway->id}/mode", ['mode' => 'sandbox'])->assertSessionHasNoErrors();
        expect($gateway->fresh()->mode)->toBe(GatewayMode::Sandbox);
    });
});

describe('credentials', function () {
    it('stores write-only encrypted credentials per mode with hints and value-free history', function () {
        $gateway = payGateway([], []);
        $this->actingAs(payStaff(), 'admin');

        $this->put("/admin/payments/gateways/{$gateway->id}/credentials/sandbox", ['credentials' => ['api_key' => PAY_API_KEY, 'webhook_secret' => 'short']])
            ->assertSessionHas('status', '2 credentials saved.');
        $this->put("/admin/payments/gateways/{$gateway->id}/credentials/sandbox", ['credentials' => ['api_key' => '', 'webhook_secret' => PAY_WEBHOOK_SECRET]]);

        $raw = DB::table('payment_gateway_credentials')->where('key', 'api_key')->value('value');
        $html = $this->get("/admin/payments/gateways/{$gateway->id}")->assertOk()->getContent();

        expect($raw)->not->toContain(PAY_API_KEY)->and(decrypt($raw, false))->toBe(PAY_API_KEY)
            ->and(PaymentGatewayCredential::where('key', 'api_key')->first()->hint)->toBe('91ab')
            ->and(PaymentGatewayCredential::where('key', 'api_key')->first()->toArray())->not->toHaveKey('value')
            ->and(str_contains($html, PAY_API_KEY))->toBeFalse()->and(str_contains($html, PAY_WEBHOOK_SECRET))->toBeFalse()
            ->and(str_contains($html, '••••91ab'))->toBeTrue()
            ->and(PaymentGatewayCredentialChange::pluck('action')->all())->toBe(['set', 'set', 'replaced'])
            ->and(json_encode(PaymentGatewayCredentialChange::all()->toArray()))->not->toContain(PAY_API_KEY)
            ->and(PaymentGatewayCredential::where('mode', 'live')->count())->toBe(0);
    });

    it('accepts only adapter-declared keys and never flashes values back', function () {
        $gateway = payGateway([], []);
        $this->actingAs(payStaff(), 'admin');

        $this->from("/admin/payments/gateways/{$gateway->id}")
            ->put("/admin/payments/gateways/{$gateway->id}/credentials/sandbox", ['credentials' => ['api_key' => PAY_API_KEY, 'base_url' => 'https://evil.example']])
            ->assertSessionHasErrors('credentials');
        expect(session()->getOldInput('credentials'))->toBeNull()->and(PaymentGatewayCredential::count())->toBe(0);

        $this->put("/admin/payments/gateways/{$gateway->id}/credentials/production", ['credentials' => ['api_key' => 'x']])->assertNotFound();
    });

    it('clears a credential with history and refuses unknown keys', function () {
        $gateway = payGateway();
        $this->actingAs(payStaff(), 'admin');

        $this->patch("/admin/payments/gateways/{$gateway->id}/credentials/sandbox/api_key/clear")->assertSessionHas('status', 'Credential cleared.');
        $this->patch("/admin/payments/gateways/{$gateway->id}/credentials/sandbox/api_key/clear")->assertSessionHas('status', 'That credential was not set.');
        $this->patch("/admin/payments/gateways/{$gateway->id}/credentials/sandbox/other_key/clear")->assertSessionHasErrors('credentials');

        expect(PaymentGatewayCredentialChange::pluck('action')->all())->toBe(['cleared'])
            ->and(app(GatewayRegistry::class)->isConfigured($gateway->fresh()))->toBeFalse();
    });

    it('keeps credential values out of gateway JSON, debug output and history tables', function () {
        $gateway = payGateway();
        $context = app(GatewayRegistry::class)->contextFor($gateway);

        expect(json_encode($gateway->load('credentials')->toArray()))->not->toContain(PAY_API_KEY)
            ->and(print_r($context, true))->not->toContain(PAY_API_KEY)->not->toContain(PAY_WEBHOOK_SECRET)
            ->and(Schema::hasColumn('payment_gateway_credential_changes', 'value'))->toBeFalse()
            ->and(Schema::hasColumn('payment_gateway_credential_changes', 'hint'))->toBeFalse();
    });
});

describe('settings', function () {
    it('validates the funding limits against each other and the pricing maximum', function () {
        $this->actingAs(payStaff(), 'admin');
        $base = ['app__name' => 'Nadabo Global Data', 'app__currency' => 'NGN', 'app__currency_symbol' => '₦', 'app__timezone' => 'Africa/Lagos',
            'app__maintenance_mode' => '0', 'pricing__max_amount_kobo' => '1000000000', 'payments__min_funding_kobo' => '10000',
            'payments__max_funding_kobo' => '50000000', 'payments__pending_expiry_minutes' => '60', 'payments__live_enabled' => '0'];

        $this->put('/admin/settings', ['settings' => ['payments__max_funding_kobo' => '2000000000'] + $base])->assertSessionHasErrors('settings.payments__max_funding_kobo');
        $this->put('/admin/settings', ['settings' => ['payments__min_funding_kobo' => '60000000'] + $base])->assertSessionHasErrors('settings.payments__min_funding_kobo');
        $this->put('/admin/settings', ['settings' => ['payments__pending_expiry_minutes' => '1'] + $base])->assertSessionHasErrors('settings.payments__pending_expiry_minutes');
        $this->put('/admin/settings', ['settings' => ['payments__min_funding_kobo' => '20000'] + $base])->assertSessionHasNoErrors();

        expect(PaymentLimits::minFundingKobo())->toBe(20_000);
    });
});

it('seeds no gateways, credentials or payments', function () {
    $this->seed();

    expect(PaymentGateway::count())->toBe(0)->and(PaymentGatewayCredential::count())->toBe(0)->and(Payment::count())->toBe(0)
        ->and(config('payments.drivers'))->toBe(['fake' => FakeGateway::class, 'http-test' => HttpTestGateway::class]);
});

it('ships with no gateway driver registered and only the approved payment tables', function () {
    $config = require base_path('config/payments.php');

    expect($config['drivers'])->toBe([]);
    foreach (['payment_attempts', 'purchases', 'provider_attempts', 'deposits', 'withdrawals', 'refunds', 'virtual_accounts', 'reserved_accounts', 'payment_points'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} exists");
    }
    foreach (['payment_gateways', 'payment_gateway_credentials', 'payment_gateway_credential_changes', 'payments', 'payment_webhooks', 'payment_status_changes'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} missing");
    }
    expect(collect(File::allFiles(app_path()))->map->getFilename()->filter(fn ($f) => preg_match('/(monnify|aspfiy)/i', $f))->all())->toBe([]);
});
