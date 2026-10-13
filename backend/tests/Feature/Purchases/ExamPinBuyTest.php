<?php

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanProviderRoute;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseIdentityRecipient;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderResultFields;
use App\Services\Providers\ProviderAdapterRegistry;
use App\Services\Purchases\PurchaseCatalog;
use App\Services\Settings\SettingsStore;
use App\Support\Enums\UserType;
use App\Support\MaintenanceMode;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Purchases\RecipientType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 11 CP4: customer Buy Exam PIN, through the same PurchaseService as
 * every purchase. There is nothing to enter but the plan (no quantity, no
 * phone, NIN, BVN, email or candidate details); the confirmation is an
 * encrypted payload bound to the customer, service, plan, amount and a
 * one-time token, payable for exactly 10 minutes. The PIN and serial the
 * provider delivers are shown in full only on the owner's own result page,
 * escaped and never cached. Purchases run with the test-only FakeProvider;
 * the production adapter list stays empty (tested first). Result values are
 * neutral fixtures generated when the tests run (D1): never a real PIN.
 */

const XBT_INVALID_CONFIRMATION = 'This confirmation is no longer valid. Please start again.';

const XBT_EXPIRED_CONFIRMATION = 'This confirmation has expired. Please start again.';

beforeEach(function () {
    FakeProvider::reset(); // its static call log must not carry over from another test file
    Http::preventStrayRequests();
});

/** An available fixed-price plan of the existing exam-pin service ($priceKobo for Subscribers) with an executable FakeProvider route. */
function xbtPlan(array $attributes = [], int $priceKobo = 15_000, bool $route = true): Plan
{
    $service = Service::where('slug', 'exam-pin')->first() ?? Service::factory()->create(['name' => 'Exam PIN', 'slug' => 'exam-pin']);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Fixture exam '.Str::random(4),
        'code' => 'exam-pin-test-'.Str::lower(Str::random(6)), 'network' => null]);
    $plan = Plan::factory()->create($attributes + ['product_id' => $product->id, 'name' => 'Fixture PIN '.Str::random(4), 'code' => $product->code.'-p',
        'amount_type' => 'fixed']);
    PlanPrice::factory()->create($plan->amount_type->value === 'fixed'
        ? ['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => $priceKobo]
        : ['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => null, 'discount_bps' => 0, 'fee_kobo' => 0]);
    if ($route) {
        puxRoute($plan, 1, ['cost_type' => 'fixed', 'cost_kobo' => 10_000]);
    }

    return $plan->fresh();
}

/** Posts the Buy page form; the response is the confirmation page when everything is valid. */
function xbtConfirm($test, Plan $plan)
{
    return $test->post('/buy/exam-pin/confirm', ['plan' => $plan->id]);
}

/** Confirms, then pays, through the HTTP flow; returns the payment response. */
function xbtBuy($test, Plan $plan)
{
    return $test->post('/buy/exam-pin', ['confirmation' => xbtConfirm($test, $plan)->assertOk()->viewData('confirmation')]);
}

/** A payload sealed with the app key, as only the server (or someone holding its key) can. */
function xbtSeal(array $payload): string
{
    return Crypt::encryptString(json_encode($payload));
}

/** The sealed payload with one bit of its ciphertext flipped. */
function xbtAlter(string $sealed): string
{
    $envelope = json_decode(base64_decode($sealed), true);
    $value = base64_decode($envelope['value']);
    $value[0] = chr(ord($value[0]) ^ 1);
    $envelope['value'] = base64_encode($value);

    return base64_encode(json_encode($envelope));
}

/** The Buy page tile of one service. */
function xbtTile(string $html, string $slug): string
{
    preg_match('#<li[^>]*data-buy-service="'.$slug.'">(.*?)</li>#s', $html, $match);

    return $match[0] ?? '';
}

/** Every URL in the page: links, form actions, sources. */
function xbtUrls(string $html): array
{
    preg_match_all('/(?:href|action|src|formaction)="([^"]*)"/', $html, $matches);

    return $matches[1];
}

/** The names of the inputs inside the page's forms. */
function xbtInputNames(string $html): array
{
    preg_match_all('/<(?:input|select|textarea)[^>]*name="([^"]*)"/', $html, $matches);

    return array_values(array_unique($matches[1]));
}

function xbtRecordLogs(): ArrayObject
{
    $lines = new ArrayObject;
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $lines->append(
        $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

    return $lines;
}

function xbtStaff(): SystemUser
{
    (new RolesAndPermissionsSeeder)->run();
    $staff = SystemUser::factory()->create();
    $staff->assignRole('super-admin');

    return $staff;
}

function xbtBalance(User $user): int
{
    return Wallet::where('user_id', $user->id)->sole()->balance_kobo;
}

function xbtNothingBought(): void
{
    expect(Purchase::count())->toBe(0)
        ->and(Transaction::where('type', 'purchase')->count())->toBe(0)
        ->and(FakeProvider::$calls)->toBe([]);
}

function xbtClean(): void
{
    expect(Artisan::call('wallet:verify'))->toBe(0)
        ->and(Artisan::call('purchases:verify'))->toBe(0, Artisan::output());
}

describe('production state: no provider adapter installed', function () {
    it('lists Exam PIN as not available right now, and cannot confirm, buy or debit anything', function () {
        $config = require base_path('config/providers.php');
        expect($config['drivers'])->toBe([]);
        config(['providers.drivers' => $config['drivers']]);
        $plan = xbtPlan(); // its route names the test driver, which is not installed
        $user = puxCustomer(100_000);
        $this->actingAs($user);

        expect(app(ProviderAdapterRegistry::class)->executableFor($plan))->toBe([])
            ->and(app(PurchaseCatalog::class)->plans($user, 'exam-pin')->all())->toBe([]);
        $tile = xbtTile($this->get('/buy')->assertOk()->getContent(), 'exam-pin');
        expect($tile)->toContain('Exam PIN')->toContain('data-unavailable')->toContain('Not available right now.')->not->toContain('href=');
        $this->get('/dashboard')->assertDontSee('data-customer-bottom-nav="buy"', false);
        $this->get('/buy/exam-pin')->assertOk()->assertSee('Buy Exam PIN')->assertSee('Not available right now. Please try again later.')
            ->assertDontSee('data-buy-form', false);
        xbtConfirm($this, $plan)->assertSessionHasErrors(['plan' => 'This plan is not available right now.']);
        // Even a correctly sealed confirmation (as one made while an adapter was installed would be) buys nothing.
        $sealed = xbtSeal(['customer' => $user->id, 'service' => 'exam-pin', 'plan' => $plan->id, 'amount' => 15_000, 'token' => (string) Str::uuid(),
            'issued_at' => now()->getTimestamp()]);
        $this->post('/buy/exam-pin', ['confirmation' => $sealed])->assertRedirect('/buy/exam-pin')
            ->assertSessionHasErrors(['purchase' => 'This plan is not available right now.']);

        xbtNothingBought();
        expect(xbtBalance($user))->toBe(100_000);
    });
});

describe('with the test-only FakeProvider', function () {
    beforeEach(function () {
        puxDrivers();
        FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn', 'exam-pin'];
    });

    describe('Buy page', function () {
        it('links the Exam PIN tile to its own Buy page once purchasable', function () {
            xbtPlan();
            $this->actingAs(puxCustomer());

            expect(xbtTile($this->get('/buy')->assertOk()->getContent(), 'exam-pin'))->toContain('href="'.route('buy.exam-pin').'"')->toContain('Buy Exam PIN')
                ->and(route('buy.exam-pin'))->toBe(url('/buy/exam-pin'))
                ->and(PurchaseCatalog::buyUrl('exam-pin'))->toBe(url('/buy/exam-pin'))
                ->and(PurchaseCatalog::SERVICES['exam-pin'])->toBe('Exam PIN');
            $this->get('/dashboard')->assertSee('data-customer-bottom-nav="buy"', false);
        });

        it('offers only the active, purchasable fixed-price plans of Exam PIN, and never debits on display', function () {
            $plan = xbtPlan(['description' => 'Fixture plan description']);
            $inactive = xbtPlan();
            $inactive->forceFill(['is_active' => false])->save();
            $variable = xbtPlan(['amount_type' => 'variable', 'min_amount_kobo' => 5_000, 'max_amount_kobo' => 50_000]);
            $unrouted = xbtPlan([], 15_000, false);
            $unpriced = xbtPlan();
            PlanPrice::where('plan_id', $unpriced->id)->update(['is_active' => false]);
            $nin = puxPlan('nin', 15_000);
            puxRoute($nin);
            $user = puxCustomer(100_000);

            $html = $this->actingAs($user)->get('/buy/exam-pin')->assertOk()->getContent();

            expect($html)->toContain('data-plan="'.$plan->id.'"')->toContain($plan->product->name.' · '.$plan->name)->toContain('Fixture plan description')
                ->toContain('₦150.00')->toContain('One PIN per purchase.')
                ->and(app(PurchaseCatalog::class)->plans($user, 'exam-pin')->pluck('plan.id')->all())->toBe([$plan->id]);
            foreach ([$inactive, $variable, $unrouted, $unpriced, $nin] as $hidden) {
                expect($html)->not->toContain('data-plan="'.$hidden->id.'"');
            }
            expect(xbtBalance($user))->toBe(100_000)->and(Purchase::count())->toBe(0);
        });

        it('asks only for a plan: no quantity, candidate, phone, NIN, BVN or email field', function () {
            xbtPlan();
            $html = $this->actingAs(puxCustomer())->get('/buy/exam-pin')->assertOk()->getContent();

            expect(xbtInputNames($html))->toEqualCanonicalizing(['_token', 'plan'])
                ->and(strtolower($html))->not->toContain('quantity')->not->toContain('candidate');
        });
    });

    describe('confirmation', function () {
        it('shows Exam PIN, the product, plan, price, one PIN and the balance, never cached, with nothing but the sealed confirmation to send', function () {
            $this->freezeTime();
            $plan = xbtPlan(['description' => 'Fixture plan description']);
            $user = puxCustomer(100_000);

            $response = xbtConfirm($this->actingAs($user), $plan)->assertOk();

            $html = $response->getContent();
            $payload = json_decode(Crypt::decryptString($response->viewData('confirmation')), true);
            expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
                ->and($html)->toContain('Confirm your purchase')
                ->toContain('<dt class="text-navy-600">Service</dt><dd class="mt-0.5 font-medium text-navy-900">Exam PIN</dd>')
                ->toContain($plan->product->name)->toContain($plan->name)->toContain('Fixture plan description')
                ->toContain('data-quantity>1 PIN</dd>')->toContain('₦150.00')->toContain('Wallet balance: <span class="tabular-nums">₦1,000.00</span>')
                ->not->toContain('data-low-balance')->not->toContain('consent')
                ->and(xbtInputNames($html))->toEqualCanonicalizing(['_token', 'confirmation'])
                ->and(array_keys($payload))->toBe(['customer', 'service', 'plan', 'amount', 'token', 'issued_at'])
                ->and($payload['customer'])->toBe($user->id)
                ->and($payload['service'])->toBe('exam-pin')
                ->and($payload['plan'])->toBe($plan->id)
                ->and($payload['amount'])->toBe(15_000)
                ->and(Str::isUuid($payload['token']))->toBeTrue()
                ->and($payload['issued_at'])->toBe(now()->getTimestamp());
            xbtNothingBought();
        });

        it('requires a plan of Exam PIN that is available now', function () {
            $nin = puxPlan('nin', 15_000);
            puxRoute($nin);
            $variable = xbtPlan(['amount_type' => 'variable', 'min_amount_kobo' => 5_000, 'max_amount_kobo' => 50_000]);
            $this->actingAs(puxCustomer(100_000));

            $this->post('/buy/exam-pin/confirm', [])->assertSessionHasErrors(['plan' => 'Choose a plan.']);
            $this->post('/buy/exam-pin/confirm', ['plan' => 'x'])->assertSessionHasErrors('plan');
            xbtConfirm($this, $nin)->assertSessionHasErrors(['plan' => 'This plan is not available right now.']);
            xbtConfirm($this, $variable)->assertSessionHasErrors(['plan' => 'This plan is not available right now.']);
            xbtNothingBought();
        });

        it('gives every confirmation its own one-time token', function () {
            $plan = xbtPlan();
            $this->actingAs(puxCustomer());

            $tokens = collect(range(1, 3))->map(fn () => json_decode(Crypt::decryptString(xbtConfirm($this, $plan)->viewData('confirmation')), true)['token']);

            expect($tokens->unique())->toHaveCount(3);
        });

        it('warns before a low balance and refuses it on payment with no debit or provider call', function () {
            $plan = xbtPlan();
            $user = puxCustomer(10_000);
            $confirm = xbtConfirm($this->actingAs($user), $plan)->assertOk()->assertSee('data-low-balance', false);

            $this->post('/buy/exam-pin', ['confirmation' => $confirm->viewData('confirmation')])->assertRedirect('/buy/exam-pin')
                ->assertSessionHasErrors(['purchase' => 'The wallet balance is not enough for this debit.']);

            xbtNothingBought();
            expect(xbtBalance($user))->toBe(10_000);
        });
    });

    describe('payment', function () {
        it('buys through PurchaseService: one purchase with no recipient, one debit, one provider call, then the result page by reference only', function () {
            $plan = xbtPlan();
            $user = puxCustomer(100_000);
            FakeProvider::$purchaseScript = ['succeeded'];
            FakeProvider::$resultScript = [FakeProvider::fixtureFields()];

            $response = xbtBuy($this->actingAs($user), $plan);

            $purchase = Purchase::sole();
            $response->assertRedirect(route('purchases.show', $purchase->reference));
            expect($response->headers->get('Location'))->toBe(url('/purchases/'.$purchase->reference))
                ->and($purchase->user_id)->toBe($user->id)
                ->and($purchase->recipient_type)->toBe(RecipientType::None)
                ->and($purchase->recipient)->toBeNull()
                ->and($purchase->request_fingerprint)->toBeNull()
                ->and($purchase->status)->toBe(PurchaseStatus::Successful)
                ->and($purchase->amount_kobo)->toBe(15_000)
                ->and(PurchaseIdentityRecipient::count())->toBe(0)
                ->and(FakeProvider::$calls)->toHaveCount(1)
                ->and(FakeProvider::$calls[0])->toBeInstanceOf(ProviderPurchaseRequest::class)
                ->and(FakeProvider::$calls[0]->recipient)->toBe('')
                ->and(FakeProvider::$calls[0]->recipientType)->toBe('none')
                ->and(xbtBalance($user))->toBe(85_000);
            xbtClean();
        });

        it('fails closed on a confirmation that is missing, does not decrypt or does not match', function (Closure $tamper) {
            $plan = xbtPlan();
            $nin = puxPlan('nin', 15_000);
            puxRoute($nin);
            $user = puxCustomer(100_000);
            $stranger = puxCustomer(100_000);
            $this->actingAs($user);
            $sealed = xbtConfirm($this, $plan)->viewData('confirmation');
            $payload = json_decode(Crypt::decryptString($sealed), true);

            $this->post('/buy/exam-pin', $tamper($sealed, $payload, $stranger, $nin))->assertRedirect('/buy/exam-pin')
                ->assertSessionHasErrors(['purchase' => XBT_INVALID_CONFIRMATION]);

            xbtNothingBought();
            expect(xbtBalance($user))->toBe(100_000)->and(xbtBalance($stranger))->toBe(100_000);
        })->with([
            'missing' => fn () => [],
            'empty' => fn () => ['confirmation' => ''],
            'a list' => fn ($sealed) => ['confirmation' => [$sealed]],
            'too long' => fn ($sealed) => ['confirmation' => str_pad($sealed, 5_000, 'A')],
            'not encrypted' => fn ($sealed, $payload) => ['confirmation' => base64_encode(json_encode($payload))],
            'altered' => fn ($sealed) => ['confirmation' => xbtAlter($sealed)],
            'not JSON inside' => fn () => ['confirmation' => Crypt::encryptString('not json')],
            'serialized inside' => fn ($sealed, $payload) => ['confirmation' => Crypt::encrypt($payload)],
            'nested too deep' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(['extra' => [[[[1]]]]] + $payload)],
            'another customer' => fn ($sealed, $payload, $stranger) => ['confirmation' => xbtSeal(['customer' => $stranger->id] + $payload)],
            'customer id as text' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(['customer' => (string) $payload['customer']] + $payload)],
            'no customer' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(Arr::except($payload, 'customer'))],
            'another service' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(['service' => 'nin'] + $payload)],
            'no service' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(Arr::except($payload, 'service'))],
            'a plan of another service' => fn ($sealed, $payload, $stranger, $nin) => ['confirmation' => xbtSeal(['plan' => $nin->id] + $payload)],
            'a plan that does not exist' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(['plan' => 999_999] + $payload)],
            'plan id as text' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(['plan' => (string) $payload['plan']] + $payload)],
            'no amount' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(Arr::except($payload, 'amount'))],
            'zero amount' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(['amount' => 0] + $payload)],
            'amount as text' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(['amount' => (string) $payload['amount']] + $payload)],
            'token not a UUID' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(['token' => 'not-a-uuid'] + $payload)],
            'no token' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(Arr::except($payload, 'token'))],
            'no issue time' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(Arr::except($payload, 'issued_at'))],
            'issue time as text' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(['issued_at' => (string) $payload['issued_at']] + $payload)],
            'issued in the future' => fn ($sealed, $payload) => ['confirmation' => xbtSeal(['issued_at' => $payload['issued_at'] + 60] + $payload)],
        ]);

        it('binds the confirmation to its customer and its service: another customer, or the NIN/BVN pages, cannot use it', function () {
            $plan = xbtPlan();
            $nin = puxPlan('nin', 15_000);
            puxRoute($nin);
            $owner = puxCustomer(100_000);
            $thief = puxCustomer(100_000);
            $sealed = xbtConfirm($this->actingAs($owner), $plan)->viewData('confirmation');
            $ninSealed = $this->post('/buy/nin/confirm', ['plan' => $nin->id, 'identity_number' => (string) random_int(10_000_000_000, 99_999_999_999)])
                ->assertOk()->viewData('confirmation');

            $this->post('/buy/exam-pin', ['confirmation' => $ninSealed])->assertRedirect('/buy/exam-pin')
                ->assertSessionHasErrors(['purchase' => XBT_INVALID_CONFIRMATION]);
            $this->post('/buy/nin', ['confirmation' => $sealed, 'consent' => '1'])->assertRedirect('/buy/nin')
                ->assertSessionHasErrors(['purchase' => XBT_INVALID_CONFIRMATION]);
            $this->actingAs($thief)->post('/buy/exam-pin', ['confirmation' => $sealed])->assertRedirect('/buy/exam-pin')
                ->assertSessionHasErrors(['purchase' => XBT_INVALID_CONFIRMATION]);

            xbtNothingBought();
            expect(xbtBalance($owner))->toBe(100_000)->and(xbtBalance($thief))->toBe(100_000);
        });

        it('turns a repeated submission of one confirmation into one purchase, one debit and one provider call', function () {
            $plan = xbtPlan();
            $user = puxCustomer(100_000);
            FakeProvider::$purchaseScript = ['timeout'];
            $sealed = xbtConfirm($this->actingAs($user), $plan)->viewData('confirmation');

            $locations = collect(range(1, 3))->map(fn () => $this->post('/buy/exam-pin', ['confirmation' => $sealed])->headers->get('Location'));

            $purchase = Purchase::sole();
            expect($locations->unique()->values()->all())->toBe([url('/purchases/'.$purchase->reference)])
                ->and(FakeProvider::$calls)->toHaveCount(1)
                ->and(Transaction::where('type', 'purchase')->count())->toBe(1)
                ->and(xbtBalance($user))->toBe(85_000);
        });

        it('refuses a paid token replayed for another plan, another amount or another service, buying nothing more', function () {
            $plan = xbtPlan();
            $other = xbtPlan();
            $nin = puxPlan('nin', 15_000);
            puxRoute($nin);
            $user = puxCustomer(100_000);
            FakeProvider::$purchaseScript = ['timeout'];
            $this->actingAs($user);
            $sealed = xbtConfirm($this, $plan)->viewData('confirmation');
            $this->post('/buy/exam-pin', ['confirmation' => $sealed])->assertRedirect();
            $payload = json_decode(Crypt::decryptString($sealed), true);
            $replayed = 'This request was already used for a different purchase. Please start again.';

            $this->post('/buy/exam-pin', ['confirmation' => xbtSeal(['plan' => $other->id] + $payload)])->assertRedirect('/buy/exam-pin')
                ->assertSessionHasErrors(['purchase' => $replayed]);
            $this->post('/buy/exam-pin', ['confirmation' => xbtSeal(['amount' => 15_001] + $payload)])->assertRedirect('/buy/exam-pin')
                ->assertSessionHasErrors(['purchase' => $replayed]);
            $this->post('/buy/nin', ['confirmation' => xbtSeal(['service' => 'nin', 'plan' => $nin->id,
                'number' => (string) random_int(10_000_000_000, 99_999_999_999)] + $payload), 'consent' => '1'])->assertRedirect('/buy/nin')
                ->assertSessionHasErrors(['purchase' => $replayed]);

            expect(Purchase::count())->toBe(1)
                ->and(PurchaseIdentityRecipient::count())->toBe(0)
                ->and(FakeProvider::$calls)->toHaveCount(1)
                ->and(Transaction::where('type', 'purchase')->count())->toBe(1)
                ->and(xbtBalance($user))->toBe(85_000);
        });

        it('refuses when the price changed after confirmation, or the confirmed amount is not the price', function () {
            $plan = xbtPlan();
            $user = puxCustomer(100_000);
            $this->actingAs($user);
            $sealed = xbtConfirm($this, $plan)->viewData('confirmation');
            $payload = json_decode(Crypt::decryptString($sealed), true);

            $this->post('/buy/exam-pin', ['confirmation' => xbtSeal(['amount' => 14_000] + $payload)])->assertRedirect('/buy/exam-pin')
                ->assertSessionHasErrors(['purchase' => 'The price has changed. Please review the new price and confirm again.']);
            PlanPrice::where('plan_id', $plan->id)->update(['price_kobo' => 16_000]);
            $this->post('/buy/exam-pin', ['confirmation' => $sealed])->assertRedirect('/buy/exam-pin')
                ->assertSessionHasErrors(['purchase' => 'The price has changed. Please review the new price and confirm again.']);

            xbtNothingBought();
            expect(xbtBalance($user))->toBe(100_000);
        });

        it('refuses a plan that stopped being purchasable after confirmation', function (Closure $break, string $message) {
            $plan = xbtPlan();
            $user = puxCustomer(100_000);
            $sealed = xbtConfirm($this->actingAs($user), $plan)->viewData('confirmation');

            $break($plan);

            $error = $this->post('/buy/exam-pin', ['confirmation' => $sealed])->assertRedirect('/buy/exam-pin')
                ->assertSessionHasErrors('purchase')->getSession()->get('errors')->first('purchase');
            expect($error)->toStartWith($message);
            xbtNothingBought();
            expect(xbtBalance($user))->toBe(100_000);
        })->with([
            'plan disabled' => [fn (Plan $plan) => $plan->forceFill(['is_active' => false])->save(), 'Plan unavailable'],
            'price disabled' => [fn (Plan $plan) => PlanPrice::where('plan_id', $plan->id)->update(['is_active' => false]), 'Price disabled'],
            'route disabled' => [fn (Plan $plan) => PlanProviderRoute::where('plan_id', $plan->id)->update(['is_active' => false]),
                'This plan is not available right now.'],
            'adapter removed' => [fn () => config(['providers.drivers' => []]), 'This plan is not available right now.'],
        ]);

        it('takes everything from the sealed confirmation, ignoring client-sent plans, prices, recipients, quantities, customers and statuses', function () {
            $plan = xbtPlan();
            $cheap = xbtPlan([], 100);
            $user = puxCustomer(100_000);
            $other = puxCustomer(100_000);
            FakeProvider::$purchaseScript = ['timeout'];
            $sealed = xbtConfirm($this->actingAs($user), $plan)->viewData('confirmation');

            $this->post('/buy/exam-pin', ['confirmation' => $sealed, 'plan' => $cheap->id, 'amount' => 1, 'confirmed_amount_kobo' => 1, 'price_kobo' => 1,
                'recipient' => '08012345678', 'phone' => '08012345678', 'identity_number' => (string) random_int(10_000_000_000, 99_999_999_999),
                'email' => 'fixture@example.test', 'candidate' => 'FIXTURE', 'quantity' => 5, 'user_id' => $other->id, 'customer' => $other->id,
                'status' => 'successful', 'token' => (string) Str::uuid()])->assertRedirect();

            $purchase = Purchase::sole();
            expect($purchase->plan_id)->toBe($plan->id)
                ->and($purchase->user_id)->toBe($user->id)
                ->and($purchase->amount_kobo)->toBe(15_000)
                ->and($purchase->recipient)->toBeNull()
                ->and($purchase->status)->toBe(PurchaseStatus::Pending)
                ->and($purchase->idempotency_key)->toBe(json_decode(Crypt::decryptString($sealed), true)['token'])
                ->and(FakeProvider::$calls)->toHaveCount(1)
                ->and(FakeProvider::$calls[0]->recipient)->toBe('')
                ->and(xbtBalance($user))->toBe(85_000)
                ->and(xbtBalance($other))->toBe(100_000);
        });

        it('can be paid for exactly 10 minutes after the confirmation page', function (int $seconds, bool $paid) {
            $this->freezeTime();
            $plan = xbtPlan();
            $user = puxCustomer(100_000);
            FakeProvider::$purchaseScript = ['timeout'];
            $sealed = xbtConfirm($this->actingAs($user), $plan)->assertOk()->viewData('confirmation');

            $this->travel($seconds)->seconds();
            $response = $this->post('/buy/exam-pin', ['confirmation' => $sealed]);

            if ($paid) {
                $response->assertRedirect(route('purchases.show', Purchase::sole()->reference));
                expect(xbtBalance($user))->toBe(85_000);
            } else {
                $response->assertRedirect('/buy/exam-pin')->assertSessionHasErrors(['purchase' => XBT_EXPIRED_CONFIRMATION]);
                xbtNothingBought();
                expect(xbtBalance($user))->toBe(100_000);
            }
        })->with([
            'at once' => [0, true],
            'after 9 minutes 59 seconds' => [599, true],
            'after exactly 10 minutes' => [600, false],
            'after an hour' => [3_600, false],
        ]);

        it('stays bound to its customer: another customer gets "no longer valid", even once it has expired', function () {
            $plan = xbtPlan();
            $owner = puxCustomer(100_000);
            $sealed = xbtConfirm($this->actingAs($owner), $plan)->viewData('confirmation');

            $this->travel(11)->minutes();

            $this->actingAs(puxCustomer(100_000))->post('/buy/exam-pin', ['confirmation' => $sealed])->assertRedirect('/buy/exam-pin')
                ->assertSessionHasErrors(['purchase' => XBT_INVALID_CONFIRMATION]);
            $this->actingAs($owner)->post('/buy/exam-pin', ['confirmation' => $sealed])->assertRedirect('/buy/exam-pin')
                ->assertSessionHasErrors(['purchase' => XBT_EXPIRED_CONFIRMATION]);
            xbtNothingBought();
        });

        it('offers nothing and refuses new purchases in maintenance mode, while a repeated submission still finds its purchase', function () {
            $plan = xbtPlan();
            $user = puxCustomer(100_000);
            FakeProvider::$purchaseScript = ['timeout'];
            $this->actingAs($user);
            $bought = xbtConfirm($this, $plan)->viewData('confirmation');
            $this->post('/buy/exam-pin', ['confirmation' => $bought]);
            $before = Purchase::sole();
            $waiting = xbtConfirm($this, $plan)->viewData('confirmation');

            app(SettingsStore::class)->set(MaintenanceMode::SETTING, true);

            $this->get('/buy/exam-pin')->assertOk()->assertSee('data-maintenance', false)->assertSee(MaintenanceMode::MESSAGE)
                ->assertDontSee('data-buy-form', false);
            $this->get('/buy')->assertOk()->assertSee('data-maintenance', false)->assertDontSee('data-buy-service="exam-pin"', false);
            xbtConfirm($this, $plan)->assertRedirect('/buy/exam-pin')->assertSessionHasErrors(['purchase' => MaintenanceMode::MESSAGE]);
            $this->post('/buy/exam-pin', ['confirmation' => $waiting])->assertRedirect('/buy/exam-pin')
                ->assertSessionHasErrors(['purchase' => MaintenanceMode::MESSAGE]);
            $this->post('/buy/exam-pin', ['confirmation' => $bought])->assertRedirect(route('purchases.show', $before->reference));

            expect(Purchase::count())->toBe(1)->and(xbtBalance($user))->toBe(85_000);
        });

        it('limits Exam PIN confirmations and payments per customer on their own budgets, separate from Data/Airtime and NIN/BVN', function () {
            $plan = xbtPlan();
            $data = puxPlan('data', 10_000);
            puxRoute($data);
            $nin = puxPlan('nin', 15_000);
            puxRoute($nin);
            $user = puxCustomer(100_000);
            $this->actingAs($user);

            for ($i = 0; $i < 30; $i++) {
                xbtConfirm($this, $plan)->assertOk();
            }
            xbtConfirm($this, $plan)->assertStatus(429);
            $this->post('/buy/data/confirm', ['plan' => $data->id, 'phone' => '08012345678'])->assertOk();
            $this->post('/buy/nin/confirm', ['plan' => $nin->id, 'identity_number' => (string) random_int(10_000_000_000, 99_999_999_999)])->assertOk();

            for ($i = 0; $i < 10; $i++) {
                $this->post('/buy/exam-pin', [])->assertRedirect();
            }
            $this->post('/buy/exam-pin', [])->assertStatus(429);
            $this->post('/buy/data', ['plan' => $data->id])->assertRedirect();
            $this->post('/buy/nin', [])->assertRedirect();

            // The other way round: used-up Data and NIN/BVN budgets leave Exam PIN untouched.
            $this->actingAs(puxCustomer());
            for ($i = 0; $i < 30; $i++) {
                $this->post('/buy/data/confirm', ['plan' => $data->id, 'phone' => '08012345678'])->assertOk();
                $this->post('/buy/nin/confirm', [])->assertRedirect();
            }
            $this->post('/buy/data/confirm', ['plan' => $data->id, 'phone' => '08012345678'])->assertStatus(429);
            $this->post('/buy/nin/confirm', [])->assertStatus(429);
            xbtConfirm($this, $plan)->assertOk();
            expect(Purchase::count())->toBe(0);

            $limit = fn (string $name) => (fn ($limit) => [$limit->maxAttempts, $limit->decaySeconds])(
                RateLimiter::limiter($name)(Request::create('/')->setUserResolver(fn () => $user)));
            $byIp = RateLimiter::limiter('exam-pin-store')(Request::create('/', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']));
            expect($limit('exam-pin-confirm'))->toBe([30, 60])
                ->and($limit('exam-pin-store'))->toBe([10, 60])
                ->and($limit('buy-confirm'))->toBe([30, 60])
                ->and($limit('identity-store'))->toBe([10, 60])
                ->and(RateLimiter::limiter('exam-pin-store')(Request::create('/')->setUserResolver(fn () => $user))->key)->toBe((string) $user->id)
                ->and($byIp->key)->toBe('203.0.113.9');
        });
    });

    describe('result page', function () {
        it('shows its owner every delivered field (such as the PIN and serial) in full, escaped, under "Your result", never cached', function () {
            $plan = xbtPlan();
            $user = puxCustomer(100_000);
            $pin = 'FIXTURE-'.Str::upper(Str::random(12));
            $serial = 'FIXTURE-'.Str::upper(Str::random(12));
            FakeProvider::$purchaseScript = ['succeeded'];
            FakeProvider::$resultScript = [new ProviderResultFields([
                ['key' => 'fixture_1', 'label' => 'Fixture <b>one</b>', 'value' => $pin],
                ['key' => 'fixture_2', 'label' => 'Fixture two', 'value' => $serial],
                ['key' => 'fixture_3', 'label' => 'Fixture three', 'value' => '<script>alert("FIXTURE")</script> & \'quoted\''],
            ])];
            xbtBuy($this->actingAs($user), $plan);
            $purchase = Purchase::sole();

            $response = $this->get(route('purchases.show', $purchase->reference))->assertOk();

            $html = $response->getContent();
            expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
                ->and($html)->toContain('Purchase successful')->toContain($purchase->reference)->toContain('₦150.00')
                ->and($html)->toContain('<dt class="text-navy-600">Recipient</dt><dd class="mt-0.5 font-mono tabular-nums text-navy-900">—</dd>')
                ->and($html)->toContain('<h2 id="result-heading-fields" class="text-base font-semibold text-navy-900">Your result</h2>')
                ->and($html)->toContain('data-result-field="fixture_1"><dt class="text-navy-600">Fixture &lt;b&gt;one&lt;/b&gt;</dt>')
                ->and($html)->toContain($pin)->toContain($serial)
                ->and($html)->toContain('&lt;script&gt;alert(&quot;FIXTURE&quot;)&lt;/script&gt; &amp; &#039;quoted&#039;')
                ->and($html)->not->toContain('<script>alert(')->not->toContain('<b>one</b>');
        });

        it('shows a neutral note when the result cannot be read, and nothing of it', function () {
            $plan = xbtPlan();
            $fields = FakeProvider::fixtureFields(2);
            FakeProvider::$purchaseScript = ['succeeded'];
            FakeProvider::$resultScript = [$fields];
            xbtBuy($this->actingAs(puxCustomer(100_000)), $plan);
            DB::table('purchase_results')->update(['encrypted_fields' => Crypt::encryptString('damaged')]);

            $response = $this->get(route('purchases.show', Purchase::sole()->reference))->assertOk()
                ->assertSee('The result for this purchase can’t be shown right now.')->assertSee('data-result-unavailable', false)
                ->assertDontSee('data-result-fields', false);

            expect($response->headers->get('Cache-Control'))->toBe('no-store, private');
            foreach ($fields->all() as $field) {
                expect($response->getContent())->not->toContain($field['value']);
            }
        });

        it('shows no result section until the purchase succeeds, and is never cached', function (array $script, Closure $results, string $heading) {
            $plan = xbtPlan();
            FakeProvider::$purchaseScript = $script;
            FakeProvider::$resultScript = $results();
            xbtBuy($this->actingAs(puxCustomer(100_000)), $plan);

            $response = $this->get(route('purchases.show', Purchase::sole()->reference))->assertOk()->assertSee($heading)
                ->assertDontSee('data-result-section', false);

            expect($response->headers->get('Cache-Control'))->toBe('no-store, private');
        })->with([
            'pending' => [['timeout'], fn () => [], 'Purchase pending'],
            'success without the result' => [['succeeded'], fn () => [], 'Purchase pending'],
            'success with only blank values' => [['succeeded'], fn () => [new ProviderResultFields([['key' => 'fixture_1', 'label' => 'Fixture 1',
                'value' => ' ']])], 'Purchase pending'],
            'failed' => [['failed_definite'], fn () => [], 'Purchase not completed'],
        ]);

        it('returns 404 to another customer and shows them nothing of it anywhere', function () {
            $plan = xbtPlan();
            $owner = puxCustomer(100_000);
            $other = puxCustomer(100_000);
            $fields = FakeProvider::fixtureFields(2);
            FakeProvider::$purchaseScript = ['succeeded'];
            FakeProvider::$resultScript = [$fields];
            xbtBuy($this->actingAs($owner), $plan);
            $purchase = Purchase::sole();

            $this->actingAs($other)->get(route('purchases.show', $purchase->reference))->assertNotFound();
            $pages = $this->get('/purchases')->assertOk()->getContent().$this->get('/dashboard')->assertOk()->getContent()
                .$this->get('/wallet')->assertOk()->getContent();
            foreach ([$purchase->reference, ...array_column($fields->all(), 'value')] as $needle) {
                expect($pages)->not->toContain($needle);
            }
        });

        it('needs a signed-in customer: guests and staff are sent to sign in', function () {
            $plan = xbtPlan();
            FakeProvider::$purchaseScript = ['succeeded'];
            FakeProvider::$resultScript = [FakeProvider::fixtureFields()];
            $purchase = puxService()->purchase(puxCustomer(100_000), $plan, '', null, (string) Str::uuid());

            foreach (['/buy/exam-pin', route('purchases.show', $purchase->reference)] as $url) {
                $this->get($url)->assertRedirect(route('login'));
            }
            $this->post('/buy/exam-pin/confirm', ['plan' => $plan->id])->assertRedirect(route('login'));
            $this->post('/buy/exam-pin', [])->assertRedirect(route('login'));
            $this->actingAs(xbtStaff(), 'admin')->get(route('purchases.show', $purchase->reference))->assertRedirect(route('login'));
            $this->get('/buy/exam-pin')->assertRedirect(route('login'));
            expect(Purchase::count())->toBe(1);
        });
    });

    describe('history, dashboard, wallet and privacy', function () {
        it('lists the purchase with its product and a dash, never a result value', function () {
            $plan = xbtPlan();
            $user = puxCustomer(100_000);
            $fields = FakeProvider::fixtureFields(2);
            FakeProvider::$purchaseScript = ['succeeded'];
            FakeProvider::$resultScript = [$fields];
            xbtBuy($this->actingAs($user), $plan);
            $purchase = Purchase::sole();

            $history = $this->get('/purchases')->assertOk()->getContent();
            $dashboard = $this->get('/dashboard')->assertOk()->getContent();
            $wallet = $this->get('/wallet')->assertOk()->getContent();

            foreach ([$history, $dashboard] as $page) {
                expect($page)->toContain($purchase->product_name.' · <span class="tabular-nums">—</span>');
            }
            expect($history)->toContain($purchase->reference);
            foreach (array_column($fields->all(), 'value') as $value) {
                expect($history.$dashboard.$wallet)->not->toContain($value);
            }
        });

        it('keeps the PIN and serial out of the session (even the database session store), every URL, the logs and wallet transactions', function () {
            config(['session.driver' => 'database']);
            $logs = xbtRecordLogs();
            $plan = xbtPlan();
            $user = puxCustomer(100_000);
            $fields = FakeProvider::fixtureFields(2);
            $html = [];
            $locations = [];
            $this->actingAs($user);

            $html[] = $this->get('/buy/exam-pin')->getContent();
            $confirm = xbtConfirm($this, $plan)->assertOk();
            $html[] = $confirm->getContent();
            FakeProvider::$purchaseScript = ['succeeded'];
            FakeProvider::$resultScript = [$fields];
            $locations[] = $this->post('/buy/exam-pin', ['confirmation' => $confirm->viewData('confirmation')])->headers->get('Location');
            foreach ([$locations[0], '/purchases', '/dashboard', '/wallet', '/buy'] as $url) {
                $html[] = $this->get($url)->assertOk()->getContent();
            }

            $sessions = DB::table('sessions')->get();
            $values = array_column($fields->all(), 'value');
            expect($sessions)->not->toBeEmpty()
                ->and(Purchase::sole()->status)->toBe(PurchaseStatus::Successful)
                ->and($html[2])->toContain($values[0]); // the owner's result page shows it
            foreach ($values as $value) {
                expect($sessions->toJson())->not->toContain($value)
                    ->and($sessions->map(fn ($row) => base64_decode($row->payload))->implode("\n"))->not->toContain($value)
                    ->and(json_encode(session()->all()))->not->toContain($value)
                    ->and(implode("\n", $logs->getArrayCopy()))->not->toContain($value)
                    ->and(implode("\n", $locations))->not->toContain($value)
                    ->and(Transaction::where('user_id', $user->id)->get()->toJson())->not->toContain($value);
                foreach ($html as $page) {
                    expect(xbtUrls($page))->each->not->toContain($value);
                }
            }
        });

        it('has literal Exam PIN routes with their own throttles behind the customer middleware, and leaves the other Buy routes as they were', function () {
            $routes = collect(Route::getRoutes())->filter(fn ($route) => preg_match('#^buy#', $route->uri()));

            expect($routes->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri().' '.implode(',', $route->gatherMiddleware()))->sort()->values()->all())->toBe([
                'GET|HEAD buy web,auth:web,auth.session,verified.optional',
                'GET|HEAD buy/bvn web,auth:web,auth.session,verified.optional',
                'GET|HEAD buy/exam-pin web,auth:web,auth.session,verified.optional',
                'GET|HEAD buy/nin web,auth:web,auth.session,verified.optional',
                'GET|HEAD buy/{service} web,auth:web,auth.session,verified.optional',
                'POST buy/bvn web,auth:web,auth.session,verified.optional,throttle:identity-store',
                'POST buy/bvn/confirm web,auth:web,auth.session,verified.optional,throttle:identity-confirm',
                'POST buy/exam-pin web,auth:web,auth.session,verified.optional,throttle:exam-pin-store',
                'POST buy/exam-pin/confirm web,auth:web,auth.session,verified.optional,throttle:exam-pin-confirm',
                'POST buy/nin web,auth:web,auth.session,verified.optional,throttle:identity-store',
                'POST buy/nin/confirm web,auth:web,auth.session,verified.optional,throttle:identity-confirm',
                'POST buy/{service} web,auth:web,auth.session,verified.optional,throttle:buy-store',
                'POST buy/{service}/confirm web,auth:web,auth.session,verified.optional,throttle:buy-confirm',
            ])->and(Route::getRoutes()->getByName('buy.service')->wheres)->toBe(['service' => 'data|airtime'])
                ->and(Route::getRoutes()->match(Request::create('/buy/exam-pin'))->getActionName())->toBe('App\Http\Controllers\User\ExamPinBuyController@create');
        });
    });
});
