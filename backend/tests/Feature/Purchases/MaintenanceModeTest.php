<?php

use App\Exceptions\Purchases\PurchaseException;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Purchase;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Settings\SettingsStore;
use App\Support\Enums\SystemRole;
use App\Support\MaintenanceMode;
use App\Support\Purchases\PurchaseSource;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Settings\SettingDefinitions;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Payments\FakeGateway;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';
require_once __DIR__.'/../../Support/Payments/helpers.php';

/*
 * Phase 10 Step 3, CP3: maintenance mode for new Data and Airtime purchases
 * ("app.maintenance_mode", Settings -> General). While it is on, no new
 * purchase can start through any path (Buy pages, confirmation, submission,
 * PurchaseService itself); a repeated submission still gets its existing
 * purchase, and purchases already in progress, re-checks, refunds, the admin
 * area and wallet funding keep working. Test-only FakeProvider and
 * FakeGateway; no real provider, gateway or HTTP call.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, SettingsSeeder::class]);
    puxDrivers();
    Http::preventStrayRequests();
});

function mmSet(bool $on): void
{
    app(SettingsStore::class)->set(MaintenanceMode::SETTING, $on);
}

/** An available Data plan with an executable FakeProvider route. */
function mmPlan(int $priceKobo = 50_000): Plan
{
    $plan = puxPlan('data', $priceKobo);
    puxRoute($plan);

    return $plan->fresh();
}

/** Confirms on the real confirmation page; returns the form the customer would submit. */
function mmConfirm($test, Plan $plan, string $phone = '08012345678'): array
{
    $confirm = $test->post('/buy/data/confirm', ['plan' => $plan->id, 'phone' => $phone])->assertOk();

    return ['plan' => $plan->id, 'phone' => $confirm->viewData('phone'), 'confirmed_amount_kobo' => $confirm->viewData('quote')->amountKobo,
        'token' => $confirm->viewData('token')];
}

/** Nothing was bought: no purchase, no purchase money, no provider call, the balance unchanged. */
function mmNothingBought(User $user, int $balanceKobo): void
{
    expect(Purchase::count())->toBe(0)
        ->and(Transaction::where('type', 'purchase')->count())->toBe(0)
        ->and(FakeProvider::$calls)->toBe([])
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe($balanceKobo);
}

function mmStaff(): SystemUser
{
    return SystemUser::factory()->withRole(SystemRole::SuperAdmin)->create();
}

/** The whole admin settings form with the current values, and maintenance mode set to $on. */
function mmSettingsForm(bool $on): array
{
    $fields = [];
    foreach (SettingDefinitions::all() as $key => $definition) {
        $value = app(SettingsStore::class)->get($key, $definition['value']);
        $fields[str_replace('.', '__', $key)] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    return ['settings' => ['app__maintenance_mode' => $on ? '1' : '0'] + $fields];
}

it('is off by default and explained in the General settings', function () {
    expect(MaintenanceMode::active())->toBeFalse()
        ->and(SettingDefinitions::all()[MaintenanceMode::SETTING]['value'])->toBeFalse();

    $this->actingAs(mmStaff(), 'admin')->get('/admin/settings')->assertOk()
        ->assertSee('Maintenance mode')
        ->assertSee('When on, customers cannot start new Data or Airtime purchases. Purchases already in progress, re-checks, refunds and the admin area keep working; wallet funding is not affected.')
        ->assertDontSee('Not enforced yet');
});

it('uses the approved customer message', function () {
    expect(MaintenanceMode::MESSAGE)->toBe('Buying is temporarily unavailable while we carry out maintenance. Please try again later. Purchases already in progress will still complete.');
});

describe('new purchases while maintenance mode is on', function () {
    it('shows the notice instead of the Buy pages', function () {
        mmPlan();
        puxRoute(puxPlan('airtime', 0, true), 1, ['cost_type' => 'percent', 'cost_discount_bps' => 300]);
        $this->actingAs(puxCustomer());
        $this->get('/buy')->assertSee('Buy Data')->assertSee('Buy Airtime')->assertDontSee('data-maintenance', false);

        mmSet(true);

        $this->get('/buy')->assertOk()->assertSee('data-maintenance', false)->assertSee(MaintenanceMode::MESSAGE)
            ->assertDontSee('data-buy-service', false)->assertDontSee('Buy Data')->assertDontSee('Buy Airtime');
        foreach (['data', 'airtime'] as $service) {
            $this->get("/buy/{$service}")->assertOk()->assertSee('data-maintenance', false)->assertSee(MaintenanceMode::MESSAGE)
                ->assertDontSee('data-buy-form', false)->assertDontSee('data-network-picker', false)->assertDontSee('data-plan=', false)
                ->assertDontSee('data-amount-limits', false)->assertDontSee('data-unavailable', false);
        }
    });

    it('refuses to confirm before checking anything else, and issues no token', function (string $service, array $input) {
        $plan = mmPlan();
        $user = puxCustomer(200_000);
        $this->actingAs($user);
        mmSet(true);

        $response = $this->post("/buy/{$service}/confirm", $input + ['plan' => $plan->id]);

        $response->assertRedirect(route('buy.service', $service))->assertSessionHasErrors(['purchase' => MaintenanceMode::MESSAGE])
            ->assertSessionDoesntHaveErrors(['plan', 'phone', 'amount']);
        expect($response->getContent())->not->toContain('name="token"')->not->toContain('data-confirm-form');
        $this->get(route('buy.service', $service))->assertSee('data-maintenance', false)->assertSee(MaintenanceMode::MESSAGE)
            ->assertDontSee('data-purchase-error', false)->assertDontSee('data-confirm-form', false);
        mmNothingBought($user, 200_000);
    })->with([
        'a valid confirmation' => ['data', ['phone' => '08012345678']],
        'an invalid phone number' => ['data', ['phone' => '123']],
        'no details at all' => ['data', ['plan' => '']],
        'an airtime confirmation' => ['airtime', ['phone' => '08012345678', 'amount' => '500']],
    ]);

    it('refuses a submission that was confirmed before maintenance started', function () {
        $plan = mmPlan();
        $user = puxCustomer(200_000);
        FakeProvider::$purchaseScript = ['succeeded'];
        $this->actingAs($user);
        $form = mmConfirm($this, $plan);
        mmSet(true);

        $this->post('/buy/data', $form)->assertRedirect(route('buy.service', ['data', 'network' => 'mtn']))
            ->assertSessionHasErrors(['purchase' => MaintenanceMode::MESSAGE]);

        mmNothingBought($user, 200_000);
        $this->get(route('buy.service', ['data', 'network' => 'mtn']))->assertSee('data-maintenance', false)->assertDontSee('data-purchase-error', false);
    });

    it('is refused by PurchaseService itself, before pricing, the route check or the wallet', function (Closure $arrange, string $withoutMaintenance) {
        $plan = mmPlan();
        $user = puxCustomer(200_000);
        $confirmed = $arrange($plan);
        mmSet(true);

        expect(fn () => puxService()->purchase($user, $plan, '08012345678', null, (string) Str::uuid(), $confirmed))
            ->toThrow(PurchaseException::class, MaintenanceMode::MESSAGE);
        mmNothingBought($user, 200_000);

        // The same request without maintenance reaches pricing, the route check or the wallet (or succeeds).
        mmSet(false);
        FakeProvider::$purchaseScript = ['succeeded'];
        $outcome = rescue(fn () => puxService()->purchase($user, $plan, '08012345678', null, (string) Str::uuid(), $confirmed)->status->value,
            fn (PurchaseException $e) => $e->getMessage(), false);
        expect($outcome)->toBe($withoutMaintenance);
    })->with([
        'a valid purchase' => [fn () => 50_000, 'successful'],
        'a changed price' => [fn () => 1, 'The price has changed. Please review the new price and confirm again.'],
        'a plan without a price' => [fn (Plan $plan) => tap(50_000, fn () => PlanPrice::where('plan_id', $plan->id)->delete()), 'No price for Subscriber.'],
        'no executable route' => [fn (Plan $plan) => tap(50_000, fn () => $plan->providerRoutes()->update(['is_active' => false])), 'This plan is not available right now.'],
        'not enough balance' => [fn (Plan $plan) => tap(300_000, fn () => PlanPrice::where('plan_id', $plan->id)->update(['price_kobo' => 300_000])),
            'The wallet balance is not enough for this debit.'],
    ]);

    it('refuses variable-amount airtime in PurchaseService too', function () {
        $plan = puxPlan('airtime', 0, true);
        puxRoute($plan, 1, ['cost_type' => 'percent', 'cost_discount_bps' => 300]);
        $user = puxCustomer(200_000);
        mmSet(true);

        expect(fn () => puxService()->purchase($user, $plan, '08012345678', 100_000, (string) Str::uuid()))
            ->toThrow(PurchaseException::class, MaintenanceMode::MESSAGE);
        mmNothingBought($user, 200_000);
    });
});

describe('what keeps working while maintenance mode is on', function () {
    it('returns the existing purchase for a repeated submission, without another debit or provider call', function (string $script, int $balanceKobo) {
        $plan = mmPlan();
        $user = puxCustomer(200_000);
        FakeProvider::$purchaseScript = [$script];
        $this->actingAs($user);
        $form = mmConfirm($this, $plan);
        $this->post('/buy/data', $form);
        $purchase = Purchase::sole();
        mmSet(true);

        $this->post('/buy/data', $form)->assertRedirect(route('purchases.show', $purchase->reference))->assertSessionHasNoErrors();
        $again = puxService()->purchase($user, $plan, $form['phone'], null, $form['token'], $form['confirmed_amount_kobo']);

        expect($again->id)->toBe($purchase->id)
            ->and(Purchase::count())->toBe(1)
            ->and(Transaction::where('type', 'purchase')->where('direction', 'debit')->count())->toBe(1)
            ->and(FakeProvider::$calls)->toHaveCount(1)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe($balanceKobo);
        $this->get(route('purchases.show', $purchase->reference))->assertOk()->assertSee($purchase->reference);
    })->with([
        'delivered' => ['succeeded', 150_000],
        'still pending' => ['timeout', 150_000],
        'failed and refunded' => ['failed_definite', 200_000],
    ]);

    it('still refuses a reused token with different details as before (the lookup runs first)', function () {
        $plan = mmPlan();
        FakeProvider::$purchaseScript = ['succeeded'];
        $this->actingAs(puxCustomer(200_000));
        $form = mmConfirm($this, $plan);
        $this->post('/buy/data', $form);
        mmSet(true);

        $this->post('/buy/data', ['phone' => '08099999999'] + $form)
            ->assertSessionHasErrors(['purchase' => 'This request was already used for a different purchase. Please start again.']);
        expect(Purchase::count())->toBe(1);
    });

    it('completes a purchase that was already past the check when maintenance started, with failover and refunds', function (array $script, PurchaseStatus $status, int $balanceKobo) {
        $plan = mmPlan();
        puxRoute($plan, 2);
        $user = puxCustomer(200_000);
        $purchase = puxService()->create($user, $plan, '08012345678', null, (string) Str::uuid());
        mmSet(true);
        FakeProvider::$purchaseScript = $script;

        $done = puxService()->execute($purchase);

        expect($done->status)->toBe($status)->and(FakeProvider::$calls)->toHaveCount(2)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe($balanceKobo);
    })->with([
        'delivered by the second route' => [['failed_definite', 'succeeded'], PurchaseStatus::Successful, 150_000],
        'every route failed: refunded' => [['failed_definite', 'failed_definite'], PurchaseStatus::Failed, 200_000],
    ]);

    it('settles purchases in progress through the scheduled re-checks', function (string $answer, PurchaseStatus $status, int $balanceKobo) {
        $plan = mmPlan();
        $user = puxCustomer(200_000);
        FakeProvider::$purchaseScript = ['timeout'];
        $purchase = puxService()->purchase($user, $plan, '08012345678', null, (string) Str::uuid());
        mmSet(true);
        $this->travel(3)->minutes();
        FakeProvider::$queryScript = [$answer];

        Artisan::call('purchases:reconcile');

        expect(MaintenanceMode::active())->toBeTrue()
            ->and($purchase->fresh()->status)->toBe($status)
            ->and(Transaction::where('type', 'purchase')->where('direction', 'credit')->count())->toBe($status === PurchaseStatus::Failed ? 1 : 0)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe($balanceKobo);
    })->with([
        'delivered' => ['succeeded', PurchaseStatus::Successful, 150_000],
        'not delivered: refunded once' => ['failed_definite', PurchaseStatus::Failed, 200_000],
    ]);

    it('lets staff re-check purchases in progress, including those under review', function () {
        $plan = mmPlan();
        FakeProvider::$purchaseScript = ['timeout'];
        $purchase = puxService()->purchase(puxCustomer(200_000), $plan, '08012345678', null, (string) Str::uuid());
        $this->travel(25)->hours();
        FakeProvider::$queryScript = ['unknown'];
        expect(puxService()->recheck($purchase->fresh(), PurchaseSource::Reconcile)->status)->toBe(PurchaseStatus::Review);
        mmSet(true);
        FakeProvider::$queryScript = ['succeeded'];

        $this->actingAs(mmStaff(), 'admin')->post("/admin/purchases/{$purchase->id}/recheck")
            ->assertSessionHas('status', 'Re-checked: the purchase is now Successful.');
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Successful);
    });

    it('leaves the admin area exactly as it is with maintenance off', function () {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 09:00:00', 'UTC'));
        FakeProvider::$purchaseScript = ['timeout'];
        $purchase = puxService()->purchase(puxCustomer(200_000), mmPlan(), '08012345678', null, (string) Str::uuid());
        $this->actingAs(mmStaff(), 'admin');
        $pages = ['/admin', '/admin/purchases', "/admin/purchases/{$purchase->id}", '/admin/transactions', '/admin/payments', '/admin/providers',
            '/admin/services/plans', '/admin/users', '/admin/wallet'];
        $off = collect($pages)->mapWithKeys(fn ($url) => [$url => $this->get($url)->assertOk()->getContent()]);

        mmSet(true);

        foreach ($pages as $url) {
            expect($this->get($url)->assertOk()->getContent())->toBe($off[$url]);
        }
        $this->get('/admin/settings')->assertOk();
    });

    it('leaves wallet funding unaffected', function () {
        payDrivers();
        $gateway = payGateway();
        $customer = puxCustomer(0);
        mmSet(true);

        $this->actingAs($customer)->get('/wallet')->assertOk()->assertSee('data-fund-wallet', false);
        $this->get('/wallet/fund')->assertOk()->assertSee('data-fund-form', false)->assertDontSee('data-maintenance', false);
        $response = $this->post('/wallet/fund', ['amount' => '2500', 'idempotency_key' => (string) Str::uuid(), 'gateway' => $gateway->id]);
        $payment = Payment::sole();
        $response->assertRedirect($payment->checkout_url);
        FakeGateway::pay($payment->reference);
        $this->get(route('wallet.fund.show', $payment->reference))->assertOk()->assertSee('data-payment-result="successful"', false);

        expect(Wallet::where('user_id', $customer->id)->sole()->balance_kobo)->toBe(250_000);
    });

    it('keeps the Buy menu item (which opens the notice) and hides the dashboard Buy shortcut', function () {
        mmPlan();
        $this->actingAs(puxCustomer());
        $this->get('/dashboard')->assertSee('data-shortcut="buy"', false)->assertSee('data-customer-nav="buy"', false);

        mmSet(true);

        $html = $this->get('/dashboard')->assertOk()->assertSee('data-customer-nav="buy"', false)->assertSee('data-customer-bottom-nav="buy"', false)
            ->assertDontSee('data-shortcut="buy"', false)->getContent();
        expect(substr_count($html, 'data-shortcut="'))->toBe(2);
        $this->get(route('buy'))->assertOk()->assertSee(MaintenanceMode::MESSAGE);
    });
});

it('takes effect on the next request when staff switch it in Settings, and switching it off restores buying', function () {
    $plan = mmPlan();
    $customer = puxCustomer(200_000);
    $staff = mmStaff();
    FakeProvider::$purchaseScript = ['succeeded'];

    $this->actingAs($staff, 'admin')->put('/admin/settings', mmSettingsForm(true))->assertSessionHasNoErrors()->assertRedirect(route('admin.settings'));
    expect(MaintenanceMode::active())->toBeTrue();
    $this->actingAs($customer, 'web')->get('/buy/data')->assertSee('data-maintenance', false);
    // (The admin area has its own session store, so this test checks the refusal by its redirect, not the session errors.)
    $this->post('/buy/data/confirm', ['plan' => $plan->id, 'phone' => '08012345678'])->assertRedirect(route('buy.service', 'data'));
    mmNothingBought($customer, 200_000);

    $this->actingAs($staff, 'admin')->put('/admin/settings', mmSettingsForm(false))->assertSessionHasNoErrors();
    expect(MaintenanceMode::active())->toBeFalse();
    $this->actingAs($customer, 'web')->get('/buy/data')->assertDontSee('data-maintenance', false)->assertSee('data-plan="'.$plan->id.'"', false);
    $this->post('/buy/data', mmConfirm($this, $plan))->assertRedirect(route('purchases.show', Purchase::sole()->reference));
    expect(Purchase::sole()->status)->toBe(PurchaseStatus::Successful)
        ->and(Wallet::where('user_id', $customer->id)->sole()->balance_kobo)->toBe(150_000);
});
