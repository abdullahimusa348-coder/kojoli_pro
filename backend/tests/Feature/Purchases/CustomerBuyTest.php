<?php

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Purchase;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Pricing\PriceResolver;
use App\Support\Purchases\PurchaseSource;
use App\Support\Purchases\PurchaseStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 10 Step 1, CP6: customer Buy Data / Buy Airtime, results and history.
 * Purchases run through PurchaseService with the test-only FakeProvider; the
 * production adapter list stays empty (tested first).
 */

beforeEach(function () {
    Http::preventStrayRequests();
});

function pu6Data(string $network = 'mtn', int $price = 50_000, string $name = '1GB'): Plan
{
    $plan = puxPlan('data', $price, false, ['name' => $name]);
    $plan->product->forceFill(['network' => $network, 'name' => strtoupper($network)])->save();
    puxRoute($plan);

    return $plan->fresh();
}

function pu6Airtime(): Plan
{
    $plan = puxPlan('airtime', 0, true);
    puxRoute($plan, 1, ['cost_type' => 'percent', 'cost_discount_bps' => 300]);

    return $plan->fresh();
}

/** Confirms and buys through the HTTP flow; returns the store response. */
function pu6Buy($test, Plan $plan, string $phone = '08012345678', ?string $amount = null, ?string $token = null)
{
    $service = $plan->product->service->slug;
    $confirm = $test->post("/buy/{$service}/confirm", array_filter(['plan' => $plan->id, 'phone' => $phone, 'amount' => $amount]))->assertOk();
    $quote = $confirm->viewData('quote');

    return $test->post("/buy/{$service}", array_filter([
        'plan' => $plan->id, 'phone' => $confirm->viewData('phone'), 'face_value_kobo' => $quote->faceValueKobo,
        'confirmed_amount_kobo' => $quote->amountKobo, 'token' => $token ?? $confirm->viewData('token'),
    ], fn ($v) => $v !== null));
}

describe('production state: no provider adapter installed', function () {
    it('shows Not available right now, hides Buy, and cannot buy or debit anything', function () {
        config(['providers.drivers' => []]);
        $plan = puxPlan('data', 50_000);
        puxRoute($plan);
        $user = puxCustomer(200_000);
        $this->actingAs($user);

        $this->get('/dashboard')->assertDontSee('data-customer-bottom-nav="buy"', false);
        $this->get('/buy')->assertOk()->assertSee('data-unavailable', false)->assertDontSee('Buy Data');
        $this->get('/buy/data')->assertOk()->assertSee('Not available right now')->assertDontSee('data-buy-form', false);
        $this->post('/buy/data/confirm', ['plan' => $plan->id, 'phone' => '08012345678'])->assertSessionHasErrors('plan');
        $this->post('/buy/data', ['plan' => $plan->id, 'phone' => '08012345678', 'confirmed_amount_kobo' => 50_000, 'token' => (string) Str::uuid()])
            ->assertSessionHasErrors('purchase');

        expect(Purchase::count())->toBe(0)->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(200_000);
    });
});

describe('with the test-only FakeProvider', function () {
    beforeEach(function () {
        puxDrivers();
    });

    describe('availability', function () {
        it('offers only purchasable plans', function (Closure $break) {
            $plan = pu6Data();
            $other = pu6Data('mtn', 80_000, '2GB');
            $break($plan);
            $this->actingAs(puxCustomer());

            $this->get('/buy/data?network=mtn')->assertOk()->assertDontSee('data-plan="'.$plan->id.'"', false)->assertSee('data-plan="'.$other->id.'"', false);
            $this->post('/buy/data/confirm', ['plan' => $plan->id, 'phone' => '08012345678'])->assertSessionHasErrors('plan');
            $this->post('/buy/data', ['plan' => $plan->id, 'phone' => '08012345678', 'confirmed_amount_kobo' => 50_000, 'token' => (string) Str::uuid()])
                ->assertSessionHasErrors('purchase');
            expect(Purchase::count())->toBe(0)->and(Transaction::where('type', 'purchase')->count())->toBe(0)->and(FakeProvider::$calls)->toBe([]);
        })->with([
            'plan inactive' => fn ($plan) => $plan->forceFill(['is_active' => false])->save(),
            'product inactive' => fn ($plan) => $plan->product->forceFill(['is_active' => false])->save() && Plan::factory()->create(['product_id' => pu6Data('glo')->product_id, 'code' => 'x-'.Str::random(5)]),
            'no customer price' => fn ($plan) => PlanPrice::where('plan_id', $plan->id)->delete(),
            'price disabled' => fn ($plan) => PlanPrice::where('plan_id', $plan->id)->update(['is_active' => false]),
            'no executable route' => fn ($plan) => $plan->providerRoutes()->update(['is_active' => false]),
            'adapter not installed' => fn ($plan) => $plan->providerRoutes()->first()->provider->forceFill(['driver' => 'not-installed-driver'])->save(),
        ]);

        it('shows nothing purchasable when the whole service is inactive', function () {
            $plan = pu6Data();
            $plan->product->service->forceFill(['is_active' => false])->save();

            $this->actingAs(puxCustomer())->get('/buy/data')->assertSee('Not available right now');
            $this->get('/buy')->assertSee('data-unavailable', false);
        });

        it('shows Buy in the navigation once something can be bought', function () {
            pu6Data();
            $this->actingAs(puxCustomer())->get('/dashboard')->assertSee('data-customer-bottom-nav="buy"', false);
            $this->get('/buy')->assertSee('Buy Data');
        });
    });

    describe('Buy Data', function () {
        it('lets the customer pick a network and a plan, showing their own customer-type price', function () {
            $mtn = pu6Data('mtn', 50_000, '1GB');
            $glo = pu6Data('glo', 45_000, 'Glo 1GB');
            PlanPrice::factory()->create(['plan_id' => $mtn->id, 'user_type' => 'vendor', 'price_kobo' => 40_000]);
            $this->actingAs(puxCustomer());

            $this->get('/buy/data?network=glo')->assertOk()->assertSee('data-plan="'.$glo->id.'"', false)->assertDontSee('data-plan="'.$mtn->id.'"', false)->assertSee('₦450.00');
            $this->get('/buy/data?network=mtn')->assertSee('₦500.00')->assertDontSee('₦400.00');

            $vendor = puxCustomer();
            $vendor->forceFill(['user_type' => 'vendor'])->save();
            $this->actingAs($vendor)->get('/buy/data?network=mtn')->assertSee('₦400.00');
        });

        it('confirms with the PriceResolver price and the canonical phone before buying', function () {
            $plan = pu6Data();
            $user = puxCustomer();
            $this->actingAs($user);

            $confirm = $this->post('/buy/data/confirm', ['plan' => $plan->id, 'phone' => '+234 801 234 5678'])->assertOk()
                ->assertSee('Confirm your purchase')->assertSee('08012345678')->assertSee('Confirm and pay ₦500.00');

            expect($confirm->viewData('quote')->amountKobo)->toBe(app(PriceResolver::class)->quoteFor($plan, $user)->amountKobo)
                ->and(Purchase::count())->toBe(0)->and(FakeProvider::$calls)->toBe([]);
        });

        it('buys successfully: one debit, result page shows the purchase', function () {
            $plan = pu6Data();
            $user = puxCustomer(200_000);
            FakeProvider::$purchaseScript = ['succeeded'];
            $this->actingAs($user);

            $response = pu6Buy($this, $plan);

            $purchase = Purchase::sole();
            $response->assertRedirect(route('purchases.show', $purchase->reference));
            $this->get(route('purchases.show', $purchase->reference))->assertOk()
                ->assertSee('Purchase successful')->assertSee($purchase->reference)->assertSee('08012345678')->assertSee('₦500.00')->assertSee('1GB');
            expect($purchase->status)->toBe(PurchaseStatus::Successful)->and($purchase->user_id)->toBe($user->id)
                ->and(Transaction::where('type', 'purchase')->count())->toBe(1)
                ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(150_000);
        });

        it('shows a pending result and never refunds an unclear outcome', function () {
            $plan = pu6Data();
            $user = puxCustomer(200_000);
            FakeProvider::$purchaseScript = ['timeout'];
            $this->actingAs($user);

            pu6Buy($this, $plan);

            $purchase = Purchase::sole();
            $this->get(route('purchases.show', $purchase->reference))->assertSee('Purchase pending')->assertSee('no need to buy again');
            expect($purchase->status)->toBe(PurchaseStatus::Pending)->and($purchase->refund_transaction_id)->toBeNull()
                ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(150_000);
        });

        it('shows a failed result with exactly one refund after a definite failure', function () {
            $plan = pu6Data();
            $user = puxCustomer(200_000);
            FakeProvider::$purchaseScript = ['failed_definite'];
            $this->actingAs($user);

            pu6Buy($this, $plan);

            $purchase = Purchase::sole();
            $this->get(route('purchases.show', $purchase->reference))->assertSee('Purchase not completed')->assertSee('returned to your wallet');
            expect($purchase->status)->toBe(PurchaseStatus::Failed)
                ->and(Transaction::where('type', 'purchase')->where('direction', 'credit')->count())->toBe(1)
                ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(200_000);
        });

        it('rejects invalid phone numbers without buying', function (string $phone) {
            $plan = pu6Data();
            $this->actingAs(puxCustomer());

            $this->post('/buy/data/confirm', ['plan' => $plan->id, 'phone' => $phone])->assertSessionHasErrors('phone');
            $this->post('/buy/data', ['plan' => $plan->id, 'phone' => $phone, 'confirmed_amount_kobo' => 50_000, 'token' => (string) Str::uuid()])
                ->assertSessionHasErrors('purchase');
            expect(Purchase::count())->toBe(0);
        })->with(['+2340801234567', '0801234567', '080123456789', 'abc', '2348012345678']);

        it('refuses a plan from another service submitted to this form', function () {
            $airtime = pu6Airtime();
            $this->actingAs(puxCustomer());

            $this->post('/buy/data/confirm', ['plan' => $airtime->id, 'phone' => '08012345678'])->assertSessionHasErrors('plan');
            $this->post('/buy/data', ['plan' => $airtime->id, 'phone' => '08012345678', 'confirmed_amount_kobo' => 1, 'token' => (string) Str::uuid()])
                ->assertSessionHasErrors('plan');
        });
    });

    describe('Buy Airtime', function () {
        it('prices a variable amount with discount and fee through PriceResolver', function () {
            $plan = pu6Airtime();
            $this->actingAs(puxCustomer());

            $this->get('/buy/airtime')->assertOk()->assertSee('From ₦50.00 to ₦50,000.00');
            $confirm = $this->post('/buy/airtime/confirm', ['plan' => $plan->id, 'phone' => '08012345678', 'amount' => '1,000'])->assertOk();

            $quote = $confirm->viewData('quote');
            expect([$quote->faceValueKobo, $quote->discountKobo, $quote->feeKobo, $quote->amountKobo])->toBe([100_000, 2_000, 0, 98_000]);
            $confirm->assertSee('₦1,000.00')->assertSee('₦20.00')->assertSee('Confirm and pay ₦980.00');
        });

        it('buys airtime and charges the discounted amount', function () {
            $plan = pu6Airtime();
            $user = puxCustomer(200_000);
            FakeProvider::$purchaseScript = ['succeeded'];
            $this->actingAs($user);

            pu6Buy($this, $plan, '08012345678', '1000');

            $purchase = Purchase::sole();
            expect([$purchase->face_value_kobo, $purchase->amount_kobo, $purchase->status])->toBe([100_000, 98_000, PurchaseStatus::Successful])
                ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(102_000)
                ->and(FakeProvider::$calls[0]->faceValueKobo)->toBe(100_000);
        });

        it('validates the amount against the plan limits', function (string $amount, string $message) {
            $plan = pu6Airtime();
            $this->actingAs(puxCustomer());

            $this->post('/buy/airtime/confirm', ['plan' => $plan->id, 'phone' => '08012345678', 'amount' => $amount])
                ->assertSessionHasErrors('amount');
            expect(session('errors')->first('amount'))->toStartWith($message)
                ->and(Purchase::count())->toBe(0);
        })->with([
            'below minimum' => ['49.99', 'Amount must be between'],
            'above maximum' => ['50000.01', 'Amount must be between'],
            'not a number' => ['abc', 'Enter a valid amount'],
            'three decimals' => ['100.123', 'Enter a valid amount'],
            'missing' => ['', 'Enter a valid amount'],
        ]);
    });

    describe('security and integrity', function () {
        it('requires a signed-in customer and keeps staff out of customer pages', function () {
            $plan = pu6Data();
            foreach (['/buy', '/buy/data', '/purchases'] as $url) {
                $this->get($url)->assertRedirect(route('login'));
            }
            $this->post('/buy/data', ['plan' => $plan->id])->assertRedirect(route('login'));

            (new RolesAndPermissionsSeeder)->run();
            $staff = SystemUser::factory()->create();
            $staff->assignRole('super-admin');
            $this->actingAs($staff, 'admin')->get('/buy')->assertRedirect(route('login'));
        });

        it('never shows one customer another customer\'s purchase', function () {
            $plan = pu6Data();
            FakeProvider::$purchaseScript = ['succeeded', 'succeeded'];
            $ada = puxCustomer();
            $bola = puxCustomer();
            $adaPurchase = puxService()->purchase($ada, $plan, '08011112222', null, 'a');
            $bolaPurchase = puxService()->purchase($bola, $plan, '08033334444', null, 'b');

            $this->actingAs($bola)->get(route('purchases.show', $adaPurchase->reference))->assertNotFound();
            $this->get('/purchases')->assertSee($bolaPurchase->reference)->assertDontSee($adaPurchase->reference)->assertDontSee('08011112222');
            $this->get('/purchases/PUR-NOTREAL')->assertNotFound();
        });

        it('ignores client-sent customer ids, statuses and prices', function () {
            $plan = pu6Data();
            $victim = puxCustomer(200_000);
            $user = puxCustomer(200_000);
            FakeProvider::$purchaseScript = ['timeout'];
            $this->actingAs($user);

            $this->post('/buy/data', ['plan' => $plan->id, 'phone' => '08012345678', 'confirmed_amount_kobo' => 1, 'token' => (string) Str::uuid()])
                ->assertSessionHasErrors(['purchase' => 'The price has changed. Please review the new price and confirm again.']);
            expect(Purchase::count())->toBe(0);

            $this->post('/buy/data', ['plan' => $plan->id, 'phone' => '08012345678', 'confirmed_amount_kobo' => 50_000, 'token' => (string) Str::uuid(),
                'user_id' => $victim->id, 'status' => 'successful', 'amount_kobo' => 1, 'price_kobo' => 1]);

            $purchase = Purchase::sole();
            expect($purchase->user_id)->toBe($user->id)->and($purchase->amount_kobo)->toBe(50_000)->and($purchase->status)->toBe(PurchaseStatus::Pending)
                ->and(Wallet::where('user_id', $victim->id)->sole()->balance_kobo)->toBe(200_000);
        });

        it('refuses when the price changed between confirmation and purchase', function () {
            $plan = pu6Data();
            $this->actingAs(puxCustomer());
            $confirm = $this->post('/buy/data/confirm', ['plan' => $plan->id, 'phone' => '08012345678']);
            PlanPrice::where('plan_id', $plan->id)->update(['price_kobo' => 55_000]);

            $this->post('/buy/data', ['plan' => $plan->id, 'phone' => '08012345678', 'confirmed_amount_kobo' => $confirm->viewData('quote')->amountKobo,
                'token' => $confirm->viewData('token')])->assertSessionHasErrors('purchase');
            expect(Purchase::count())->toBe(0);
        });

        it('shows no provider, cost, margin or internal error details to customers', function () {
            $plan = pu6Data();
            $user = puxCustomer();
            FakeProvider::$purchaseScript = ['failed_definite'];
            $purchase = puxService()->purchase($user, $plan, '08012345678', null, 'k');
            $provider = $plan->providerRoutes()->first()->provider;

            $html = $this->actingAs($user)->get(route('purchases.show', $purchase->reference))->getContent().$this->get('/purchases')->getContent();
            foreach ([$provider->name, $provider->code, 'Declined by provider', 'declined', 'FP-', 'PRA-', '₦450.00', 'CODE1', PUX_KEY, 'Every provider'] as $needle) {
                expect(str_contains($html, $needle))->toBeFalse("found {$needle}");
            }
        });
    });

    describe('idempotency and wallet', function () {
        it('turns a double submit of one confirmation into one purchase, one debit and one provider call', function () {
            $plan = pu6Data();
            $user = puxCustomer(200_000);
            FakeProvider::$purchaseScript = ['succeeded', 'succeeded'];
            $this->actingAs($user);
            $confirm = $this->post('/buy/data/confirm', ['plan' => $plan->id, 'phone' => '08012345678']);
            $form = ['plan' => $plan->id, 'phone' => '08012345678', 'confirmed_amount_kobo' => 50_000, 'token' => $confirm->viewData('token')];

            $first = $this->post('/buy/data', $form);
            $second = $this->post('/buy/data', $form);

            $purchase = Purchase::sole();
            $first->assertRedirect(route('purchases.show', $purchase->reference));
            $second->assertRedirect(route('purchases.show', $purchase->reference));
            expect(Transaction::where('type', 'purchase')->count())->toBe(1)->and(FakeProvider::$calls)->toHaveCount(1)
                ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(150_000);
        });

        it('rejects a reused token with different details', function () {
            $plan = pu6Data();
            FakeProvider::$purchaseScript = ['succeeded'];
            $this->actingAs(puxCustomer(200_000));
            $token = (string) Str::uuid();

            $this->post('/buy/data', ['plan' => $plan->id, 'phone' => '08012345678', 'confirmed_amount_kobo' => 50_000, 'token' => $token]);
            $this->post('/buy/data', ['plan' => $plan->id, 'phone' => '08099999999', 'confirmed_amount_kobo' => 50_000, 'token' => $token])
                ->assertSessionHasErrors(['purchase' => 'This request was already used for a different purchase. Please start again.']);

            expect(Purchase::count())->toBe(1)->and(Transaction::where('type', 'purchase')->count())->toBe(1);
        });

        it('refuses an insufficient balance with a clear message, no debit and no provider call', function () {
            $plan = pu6Data();
            $user = puxCustomer(49_999);
            $this->actingAs($user);

            $this->post('/buy/data/confirm', ['plan' => $plan->id, 'phone' => '08012345678'])->assertSee('data-low-balance', false);
            $this->post('/buy/data', ['plan' => $plan->id, 'phone' => '08012345678', 'confirmed_amount_kobo' => 50_000, 'token' => (string) Str::uuid()])
                ->assertSessionHasErrors(['purchase' => 'The wallet balance is not enough for this debit.']);

            expect(Purchase::count())->toBe(0)->and(FakeProvider::$calls)->toBe([])->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(49_999);
        });

        it('rate-limits purchase submissions per customer, separately from confirmations', function () {
            $plan = pu6Data();
            $user = puxCustomer();
            $this->actingAs($user);

            for ($i = 0; $i < 30; $i++) {
                $this->post('/buy/data/confirm', ['plan' => $plan->id, 'phone' => '08012345678'])->assertOk();
            }
            $this->post('/buy/data/confirm', ['plan' => $plan->id, 'phone' => '08012345678'])->assertStatus(429);

            for ($i = 0; $i < 10; $i++) {
                $this->post('/buy/data', ['plan' => $plan->id])->assertRedirect();
            }
            $this->post('/buy/data', ['plan' => $plan->id])->assertStatus(429);

            $this->actingAs(puxCustomer());
            $this->post('/buy/data', ['plan' => $plan->id])->assertRedirect();
            expect(Purchase::count())->toBe(0)->and(FakeProvider::$calls)->toBe([]);
        });
    });

    describe('history and results', function () {
        it('shows each status in customer language', function (array $script, string $badge, string $heading) {
            $plan = pu6Data();
            $user = puxCustomer();
            FakeProvider::$purchaseScript = $script;
            $purchase = puxService()->purchase($user, $plan, '08012345678', null, 'k');
            if ($badge === 'Under review') {
                $this->travel(25)->hours();
                puxService()->recheck($purchase, PurchaseSource::Reconcile);
            }

            $this->actingAs($user)->get(route('purchases.show', $purchase->reference))->assertSee($badge)->assertSee($heading);
            $this->get('/purchases')->assertSee($badge);
        })->with([
            'successful' => [['succeeded'], 'Successful', 'Purchase successful'],
            'pending' => [['timeout'], 'Pending', 'Purchase pending'],
            'failed' => [['failed_definite'], 'Failed', 'Purchase not completed'],
            'review' => [['timeout'], 'Under review', 'Purchase under review'],
        ]);

        it('paginates the customer history 15 per page, newest first', function () {
            $plan = pu6Data();
            $user = puxCustomer(5_000_000);
            foreach (range(1, 17) as $i) {
                FakeProvider::$purchaseScript = ['succeeded'];
                puxService()->purchase($user, $plan, '08012345678', null, 'k-'.$i);
            }
            $this->actingAs($user);

            expect(substr_count($this->get('/purchases')->getContent(), 'data-purchase="'))->toBe(15)
                ->and(substr_count($this->get('/purchases?page=2')->getContent(), 'data-purchase="'))->toBe(2);
            $this->get('/dashboard')->assertSee('data-customer-menu="purchases"', false);
        });
    });
});
