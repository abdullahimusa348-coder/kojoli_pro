<?php

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Providers\Data\ProviderResultFields;
use App\Services\Providers\ProviderAdapterRegistry;
use App\Services\Purchases\PurchaseCatalog;
use App\Services\Settings\SettingsStore;
use App\Support\Enums\UserType;
use App\Support\MaintenanceMode;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Purchases\RecipientType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 11 CP3: customer Buy NIN / Buy BVN, through the same PurchaseService
 * as every purchase. The number is only ever POSTed: never in a URL, never
 * flashed or refilled, shown in full only on the confirmation page (sent
 * no-store, private) and carried to the purchase only inside an encrypted
 * payload bound to the customer, service, plan, amount and a one-time token.
 * Afterwards only the masked number is shown, and the result only to its
 * owner. Purchases run with the test-only FakeProvider; the production
 * adapter list stays empty (tested first). Numbers and result values are
 * neutral fixtures generated when the tests run (D1).
 */

const IBT_CONSENT = [
    'nin' => 'I confirm that the NIN I entered is correct and I consent to its submission for this service.',
    'bvn' => 'I confirm that the BVN I entered is correct and I consent to its submission for this service.',
];

const IBT_INVALID_CONFIRMATION = 'This confirmation is no longer valid. Please start again.';

const IBT_EXPIRED_CONFIRMATION = 'This confirmation has expired. Please start again.';

beforeEach(function () {
    FakeProvider::reset(); // its static call log must not carry over from another test file
    Http::preventStrayRequests();
});

dataset('ibt identity types', ['NIN' => [RecipientType::Nin], 'BVN' => [RecipientType::Bvn]]);

/** A random 11-digit number, generated when the test runs. */
function ibtNumber(): string
{
    return (string) random_int(10_000_000_000, 99_999_999_999);
}

/** An available fixed-price NIN or BVN plan ($priceKobo for Subscribers) with an executable FakeProvider route. */
function ibtPlan(RecipientType $type, array $attributes = [], int $priceKobo = 15_000): Plan
{
    $service = Service::where('slug', $type->value)->first() ?? Service::factory()->create(['name' => $type->label(), 'slug' => $type->value]);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Test product', 'code' => $type->value.'-test-'.Str::lower(Str::random(6))]);
    $plan = Plan::factory()->create($attributes + ['product_id' => $product->id, 'name' => 'Test plan '.Str::random(4), 'code' => $product->code.'-p',
        'amount_type' => 'fixed']);
    PlanPrice::factory()->create($plan->amount_type->value === 'fixed'
        ? ['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => $priceKobo]
        : ['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => null, 'discount_bps' => 0, 'fee_kobo' => 0]);
    puxRoute($plan, 1, ['cost_type' => 'fixed', 'cost_kobo' => 10_000]);

    return $plan->fresh();
}

/** Posts the Buy page form; the response is the confirmation page when everything is valid. */
function ibtConfirm($test, Plan $plan, string $number)
{
    return $test->post('/buy/'.$plan->product->service->slug.'/confirm', ['plan' => $plan->id, 'identity_number' => $number]);
}

/** Confirms, then buys with consent, through the HTTP flow; returns the purchase response. */
function ibtBuy($test, Plan $plan, string $number)
{
    $confirmation = ibtConfirm($test, $plan, $number)->assertOk()->viewData('confirmation');

    return $test->post('/buy/'.$plan->product->service->slug, ['confirmation' => $confirmation, 'consent' => '1']);
}

/** A payload sealed with the app key, as only the server (or someone holding its key) can. */
function ibtSeal(array $payload): string
{
    return Crypt::encryptString(json_encode($payload));
}

/** The sealed payload with one bit of its ciphertext flipped. */
function ibtAlter(string $sealed): string
{
    $envelope = json_decode(base64_decode($sealed), true);
    $value = base64_decode($envelope['value']);
    $value[0] = chr(ord($value[0]) ^ 1);
    $envelope['value'] = base64_encode($value);

    return base64_encode(json_encode($envelope));
}

/** The Buy page tile of one service. */
function ibtTile(string $html, string $slug): string
{
    preg_match('#<li[^>]*data-buy-service="'.$slug.'">(.*?)</li>#s', $html, $match);

    return $match[0] ?? '';
}

/** Every URL in the page: links, form actions, sources. */
function ibtUrls(string $html): array
{
    preg_match_all('/(?:href|action|src|formaction)="([^"]*)"/', $html, $matches);

    return $matches[1];
}

function ibtRecordLogs(): ArrayObject
{
    $lines = new ArrayObject;
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $lines->append(
        $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

    return $lines;
}

function ibtStaff(): SystemUser
{
    (new RolesAndPermissionsSeeder)->run();
    $staff = SystemUser::factory()->create();
    $staff->assignRole('super-admin');

    return $staff;
}

function ibtBalance(User $user): int
{
    return Wallet::where('user_id', $user->id)->sole()->balance_kobo;
}

function ibtNothingBought(): void
{
    expect(Purchase::count())->toBe(0)
        ->and(Transaction::where('type', 'purchase')->count())->toBe(0)
        ->and(FakeProvider::$calls)->toBe([]);
}

function ibtClean(): void
{
    expect(Artisan::call('wallet:verify'))->toBe(0)
        ->and(Artisan::call('purchases:verify'))->toBe(0, Artisan::output());
}

describe('production state: no provider adapter installed', function () {
    it('lists NIN and BVN as not available right now, and cannot confirm, buy or debit anything', function (RecipientType $type) {
        $config = require base_path('config/providers.php');
        expect($config['drivers'])->toBe([]);
        config(['providers.drivers' => $config['drivers']]);
        $plan = ibtPlan($type); // its route names the test driver, which is not installed
        $user = puxCustomer(100_000);
        $slug = $type->value;
        $this->actingAs($user);

        expect(app(ProviderAdapterRegistry::class)->executableFor($plan))->toBe([])
            ->and(app(PurchaseCatalog::class)->plans($user, $slug)->all())->toBe([]);
        $index = $this->get('/buy')->assertOk()->getContent();
        foreach (['nin', 'bvn'] as $each) {
            expect(ibtTile($index, $each))->toContain('data-unavailable')->toContain('Not available right now.')->not->toContain('href=');
        }
        $this->get('/dashboard')->assertDontSee('data-customer-bottom-nav="buy"', false);
        $this->get("/buy/{$slug}")->assertOk()->assertSee('Not available right now. Please try again later.')
            ->assertDontSee('data-buy-form', false)->assertDontSee('identity_number', false);
        $this->post("/buy/{$slug}/confirm", ['plan' => $plan->id, 'identity_number' => ibtNumber()])
            ->assertSessionHasErrors(['plan' => 'This plan is not available right now.']);
        // Even a correctly sealed confirmation (as one made while an adapter was installed would be) buys nothing.
        $sealed = ibtSeal(['customer' => $user->id, 'service' => $slug, 'plan' => $plan->id, 'amount' => 15_000, 'token' => (string) Str::uuid(),
            'issued_at' => now()->getTimestamp(), 'number' => ibtNumber()]);
        $this->post("/buy/{$slug}", ['confirmation' => $sealed, 'consent' => '1'])->assertRedirect("/buy/{$slug}")
            ->assertSessionHasErrors(['purchase' => 'This plan is not available right now.']);

        ibtNothingBought();
        expect(ibtBalance($user))->toBe(100_000);
    })->with('ibt identity types');
});

describe('with the test-only FakeProvider', function () {
    beforeEach(function () {
        puxDrivers();
        FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn'];
    });

    describe('Buy page', function () {
        it('links the NIN and BVN tiles to their own Buy pages once purchasable', function () {
            ibtPlan(RecipientType::Nin);
            ibtPlan(RecipientType::Bvn);
            $this->actingAs(puxCustomer());

            $index = $this->get('/buy')->assertOk()->getContent();
            expect(ibtTile($index, 'nin'))->toContain('href="'.route('buy.nin').'"')->toContain('Buy NIN')
                ->and(ibtTile($index, 'bvn'))->toContain('href="'.route('buy.bvn').'"')->toContain('Buy BVN')
                ->and(route('buy.nin'))->toBe(url('/buy/nin'))
                ->and(route('buy.bvn'))->toBe(url('/buy/bvn'));
            $this->get('/dashboard')->assertSee('data-customer-bottom-nav="buy"', false);
        });

        it('offers only the active fixed-price plans of that service, and never debits on display', function () {
            $plan = ibtPlan(RecipientType::Nin, ['description' => 'Fixture plan description']);
            $inactive = ibtPlan(RecipientType::Nin);
            $inactive->forceFill(['is_active' => false])->save();
            $variable = ibtPlan(RecipientType::Nin, ['amount_type' => 'variable', 'min_amount_kobo' => 5_000, 'max_amount_kobo' => 500_000]);
            $unpriced = ibtPlan(RecipientType::Nin);
            PlanPrice::where('plan_id', $unpriced->id)->delete();
            $bvn = ibtPlan(RecipientType::Bvn);
            $user = puxCustomer(100_000);
            $this->actingAs($user);

            $this->get('/buy/nin')->assertOk()->assertSee('data-plan="'.$plan->id.'"', false)->assertSee('Fixture plan description')->assertSee('₦150.00')
                ->assertDontSee('data-plan="'.$inactive->id.'"', false)->assertDontSee('data-plan="'.$variable->id.'"', false)
                ->assertDontSee('data-plan="'.$unpriced->id.'"', false)->assertDontSee('data-plan="'.$bvn->id.'"', false);
            $this->get('/buy/bvn')->assertOk()->assertSee('data-plan="'.$bvn->id.'"', false)->assertDontSee('data-plan="'.$plan->id.'"', false);
            foreach ([$inactive, $variable, $unpriced] as $refused) {
                ibtConfirm($this, $refused, ibtNumber())->assertSessionHasErrors(['plan' => 'This plan is not available right now.']);
            }
            $this->post('/buy/nin/confirm', ['plan' => $bvn->id, 'identity_number' => ibtNumber()])
                ->assertSessionHasErrors(['plan' => 'This plan is not available right now.']);
            ibtConfirm($this, $plan, ibtNumber())->assertOk();

            ibtNothingBought();
            expect(ibtBalance($user))->toBe(100_000);
        });

        it('has an empty, unremembered number field', function (RecipientType $type) {
            ibtPlan($type);
            $html = $this->actingAs(puxCustomer())->get("/buy/{$type->value}")->assertOk()->getContent();

            preg_match_all('/<input[^>]*name="identity_number"[^>]*>/', $html, $inputs);
            expect($inputs[0])->toHaveCount(1)
                ->and($inputs[0][0])->toContain('value=""')->toContain('autocomplete="off"')->toContain('type="text"')->toContain('inputmode="numeric"')
                ->and(ibtUrls($html))->each->not->toContain('identity');
        })->with('ibt identity types');
    });

    describe('entering the number', function () {
        it('accepts only 11 digits, and never echoes, flashes or refills what was typed', function (Closure $input) {
            $plan = ibtPlan(RecipientType::Nin);
            $typed = $input();
            $this->actingAs(puxCustomer());

            $response = $this->from('/buy/nin')->post('/buy/nin/confirm', ['plan' => $plan->id, 'identity_number' => $typed]);

            $response->assertRedirect('/buy/nin')->assertSessionHasErrors(['identity_number' => 'Enter a valid 11-digit NIN.']);
            expect(session()->getOldInput())->not->toHaveKey('identity_number')
                ->and(json_encode(session()->all()))->not->toContain(json_encode($typed));
            $page = $this->get('/buy/nin')->assertOk()->assertSee('Enter a valid 11-digit NIN.')->getContent();
            expect($page)->not->toContain(e(is_array($typed) ? $typed[0] : $typed))
                ->and(Purchase::count())->toBe(0);
        })->with([
            '10 digits' => fn () => substr(ibtNumber(), 1),
            '12 digits' => fn () => ibtNumber().random_int(0, 9),
            'a letter' => fn () => substr(ibtNumber(), 1).'x',
            'dashes' => fn () => implode('-', str_split(ibtNumber(), 4)),
            'a plus sign' => fn () => '+'.ibtNumber(),
            'non-ASCII digits' => fn () => str_repeat('١', 11),
            'too long' => fn () => str_repeat(ibtNumber(), 3),
            'not text' => fn () => [ibtNumber()],
        ]);

        it('asks for the number and a plan without echoing anything', function () {
            $plan = ibtPlan(RecipientType::Bvn);
            $this->actingAs(puxCustomer());

            $this->post('/buy/bvn/confirm', ['plan' => $plan->id, 'identity_number' => '   '])->assertSessionHasErrors(['identity_number' => 'Enter the BVN.']);
            $number = ibtNumber();
            $this->post('/buy/bvn/confirm', ['identity_number' => $number])->assertSessionHasErrors(['plan' => 'Choose a plan.']);
            $this->post('/buy/bvn/confirm', ['plan' => 'x', 'identity_number' => $number])->assertSessionHasErrors('plan');

            expect(session()->getOldInput())->not->toHaveKey('identity_number')
                ->and(json_encode(session()->all()))->not->toContain($number)
                ->and(Purchase::count())->toBe(0);
        });

        it('never echoes the number in a JSON validation answer, even with debug on', function () {
            config(['app.debug' => true]);
            ibtPlan(RecipientType::Nin);
            $number = ibtNumber();
            $this->actingAs(puxCustomer());

            $response = $this->postJson('/buy/nin/confirm', ['plan' => 0, 'identity_number' => $number])->assertStatus(422)->assertJsonValidationErrors('plan');
            $invalid = substr($number, 1);
            $second = $this->postJson('/buy/nin/confirm', ['plan' => 0, 'identity_number' => $invalid])->assertStatus(422);

            expect($response->getContent())->not->toContain($number)
                ->and($second->getContent())->not->toContain($invalid);
        });
    });

    describe('confirmation', function () {
        it('shows the service, plan, price and balance, the full number once and the exact consent sentence, never cached', function (RecipientType $type) {
            $this->freezeTime();
            $plan = ibtPlan($type, ['description' => 'Fixture plan description']);
            $user = puxCustomer(100_000);
            $number = ibtNumber();
            $this->actingAs($user);

            $response = ibtConfirm($this, $plan, implode(' ', str_split($number, 4)))->assertOk(); // spaces are ignored
            $html = $response->getContent();
            preg_match('#<section[^>]*data-confirm .*?</section>#s', $html, $section);
            $other = $type === RecipientType::Nin ? 'bvn' : 'nin';

            expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
                ->and(substr_count($html, $number))->toBe(1)
                ->and($html)->toContain('data-recipient>'.$number.'</dd>')
                ->and($html)->toContain('<label for="consent" class="text-sm text-navy-800" data-consent>'.IBT_CONSENT[$type->value].'</label>')
                ->and($html)->not->toContain(IBT_CONSENT[$other])
                ->and($html)->toContain($type->label().'</dd>')->toContain($plan->name)->toContain('Fixture plan description')
                ->and($html)->toContain('data-charge>₦150.00</dd>')->toContain('Wallet balance: <span class="tabular-nums">₦1,000.00</span>')
                ->and($html)->toContain('Confirm and pay ₦150.00')
                ->and($html)->toContain('action="'.url("/buy/{$type->value}").'"')
                ->and(substr_count(strtolower(strip_tags($section[0])), 'consent'))->toBe(1)
                ->and(strtolower(strip_tags($section[0])))->not->toContain('kyc')->not->toContain('verif')->not->toContain('nimc')->not->toContain('nibss')
                ->and(ibtUrls($html))->each->not->toContain($number);
            preg_match_all('/<input[^>]*>/', $section[0], $inputs);
            expect(collect($inputs[0])->map(fn ($input) => preg_match('/name="([^"]+)"/', $input, $m) ? $m[1] : null)->all())->toBe(['_token', 'confirmation', 'consent'])
                ->and($inputs[0][2])->toContain('type="checkbox"')->toContain('required')->not->toContain('checked');

            $sealed = $response->viewData('confirmation');
            $payload = json_decode(Crypt::decryptString($sealed), true);
            expect($html)->toContain('name="confirmation" value="'.$sealed.'"')
                ->and($sealed)->not->toContain($number)
                ->and(Arr::except($payload, 'token'))->toBe(['customer' => $user->id, 'service' => $type->value, 'plan' => $plan->id, 'amount' => 15_000,
                    'issued_at' => now()->getTimestamp(), 'number' => $number])
                ->and(Str::isUuid($payload['token']))->toBeTrue();
            ibtNothingBought();
        })->with('ibt identity types');

        it('uses exactly the approved consent sentences', function () {
            expect(config('purchases.identity_consent'))->toBe(IBT_CONSENT);
        });

        it('gives every confirmation its own one-time token', function () {
            $plan = ibtPlan(RecipientType::Nin);
            $number = ibtNumber();
            $this->actingAs(puxCustomer());

            $tokens = collect(range(1, 3))->map(fn () => json_decode(Crypt::decryptString(ibtConfirm($this, $plan, $number)->viewData('confirmation')), true)['token']);

            expect($tokens->unique())->toHaveCount(3);
        });

        it('warns before a low balance and refuses it on payment with no debit or provider call', function () {
            $plan = ibtPlan(RecipientType::Bvn);
            $user = puxCustomer(14_999);
            $this->actingAs($user);

            $confirm = ibtConfirm($this, $plan, ibtNumber())->assertOk()->assertSee('data-low-balance', false);
            $this->post('/buy/bvn', ['confirmation' => $confirm->viewData('confirmation'), 'consent' => '1'])->assertRedirect('/buy/bvn')
                ->assertSessionHasErrors(['purchase' => 'The wallet balance is not enough for this debit.']);

            ibtNothingBought();
            expect(ibtBalance($user))->toBe(14_999);
        });
    });

    describe('buying', function () {
        it('buys with consent through PurchaseService: one purchase, one debit, then the result page by reference only', function (RecipientType $type) {
            $plan = ibtPlan($type);
            $user = puxCustomer(100_000);
            $number = ibtNumber();
            FakeProvider::$purchaseScript = ['succeeded'];
            FakeProvider::$resultScript = [FakeProvider::fixtureFields()];
            $this->actingAs($user);
            $sealed = ibtConfirm($this, $plan, $number)->viewData('confirmation');

            $response = $this->post("/buy/{$type->value}", ['confirmation' => $sealed, 'consent' => '1']);

            $purchase = Purchase::sole();
            $response->assertRedirect(route('purchases.show', $purchase->reference));
            expect($response->headers->get('Location'))->toBe(url('/purchases/'.$purchase->reference))
                ->and($purchase->user_id)->toBe($user->id)
                ->and($purchase->plan_id)->toBe($plan->id)
                ->and($purchase->recipient_type)->toBe($type)
                ->and($purchase->recipient)->toBeNull()
                ->and($purchase->amount_kobo)->toBe(15_000)
                ->and($purchase->idempotency_key)->toBe(json_decode(Crypt::decryptString($sealed), true)['token'])
                ->and($purchase->status)->toBe(PurchaseStatus::Successful)
                ->and($purchase->identityRecipient->masked_value)->toBe($type->mask($number))
                ->and($purchase->identityRecipient->number($type))->toBe($number)
                ->and($purchase->identityRecipient->consented_at)->not->toBeNull()
                ->and(Transaction::where('type', 'purchase')->count())->toBe(1)
                ->and(ibtBalance($user))->toBe(85_000)
                ->and(FakeProvider::$calls)->toHaveCount(1)
                ->and(FakeProvider::$calls[0]->recipient)->toBe($number);
            ibtClean();
        })->with('ibt identity types');

        it('requires the consent box: without it the confirmation is shown again, never cached, and nothing is bought', function (array $form) {
            $plan = ibtPlan(RecipientType::Nin);
            $user = puxCustomer(100_000);
            $number = ibtNumber();
            $this->actingAs($user);
            $sealed = ibtConfirm($this, $plan, $number)->viewData('confirmation');

            $response = $this->post('/buy/nin', ['confirmation' => $sealed] + $form)->assertStatus(422)
                ->assertSee('Tick the box to give your consent before you pay.')->assertSee('data-consent-error', false)
                ->assertSee(IBT_CONSENT['nin']);

            expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
                ->and(substr_count($response->getContent(), $number))->toBe(1)
                ->and(json_decode(Crypt::decryptString($response->viewData('confirmation')), true))->toBe(json_decode(Crypt::decryptString($sealed), true));
            ibtNothingBought();
            expect(ibtBalance($user))->toBe(100_000);

            FakeProvider::$purchaseScript = ['timeout'];
            $this->post('/buy/nin', ['confirmation' => $response->viewData('confirmation'), 'consent' => '1'])->assertRedirect();
            expect(Purchase::sole()->identityRecipient->consented_at)->not->toBeNull();
        })->with([
            'no box' => [[]],
            'unticked' => [['consent' => '0']],
            'empty' => [['consent' => '']],
            'not a yes' => [['consent' => 'no']],
        ]);

        it('fails closed on a confirmation that does not decrypt or does not match', function (Closure $tamper) {
            $plan = ibtPlan(RecipientType::Nin);
            $bvnPlan = ibtPlan(RecipientType::Bvn);
            $user = puxCustomer(100_000);
            $stranger = puxCustomer(100_000);
            $this->actingAs($user);
            $sealed = ibtConfirm($this, $plan, ibtNumber())->viewData('confirmation');
            $payload = json_decode(Crypt::decryptString($sealed), true);

            $this->post('/buy/nin', $tamper($sealed, $payload, $stranger, $bvnPlan) + ['consent' => '1'])->assertRedirect('/buy/nin')
                ->assertSessionHasErrors(['purchase' => IBT_INVALID_CONFIRMATION]);

            ibtNothingBought();
            expect(ibtBalance($user))->toBe(100_000)->and(ibtBalance($stranger))->toBe(100_000);
        })->with([
            'missing' => fn () => [],
            'empty' => fn () => ['confirmation' => ''],
            'a list' => fn ($sealed) => ['confirmation' => [$sealed]],
            'too long' => fn ($sealed) => ['confirmation' => str_pad($sealed, 5_000, 'A')],
            'not encrypted' => fn ($sealed, $payload) => ['confirmation' => base64_encode(json_encode($payload))],
            'altered' => fn ($sealed) => ['confirmation' => ibtAlter($sealed)],
            'not JSON inside' => fn () => ['confirmation' => Crypt::encryptString('not json')],
            'serialized inside' => fn ($sealed, $payload) => ['confirmation' => Crypt::encrypt($payload)],
            'nested too deep' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(['extra' => [[[[1]]]]] + $payload)],
            'another customer' => fn ($sealed, $payload, $stranger) => ['confirmation' => ibtSeal(['customer' => $stranger->id] + $payload)],
            'customer id as text' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(['customer' => (string) $payload['customer']] + $payload)],
            'no customer' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(Arr::except($payload, 'customer'))],
            'the other service' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(['service' => 'bvn'] + $payload)],
            'a plan of the other service' => fn ($sealed, $payload, $stranger, $bvnPlan) => ['confirmation' => ibtSeal(['plan' => $bvnPlan->id] + $payload)],
            'a plan that does not exist' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(['plan' => 999_999] + $payload)],
            'plan id as text' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(['plan' => (string) $payload['plan']] + $payload)],
            'no amount' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(Arr::except($payload, 'amount'))],
            'zero amount' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(['amount' => 0] + $payload)],
            'token not a UUID' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(['token' => 'not-a-uuid'] + $payload)],
            'no token' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(Arr::except($payload, 'token'))],
            'number of 10 digits' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(['number' => substr($payload['number'], 1)] + $payload)],
            'number with spaces' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(['number' => implode(' ', str_split($payload['number'], 4))] + $payload)],
            'number as an integer' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(['number' => (int) $payload['number']] + $payload)],
            'no number' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(Arr::except($payload, 'number'))],
            'no issue time' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(Arr::except($payload, 'issued_at'))],
            'issue time as text' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(['issued_at' => (string) $payload['issued_at']] + $payload)],
            'issued in the future' => fn ($sealed, $payload) => ['confirmation' => ibtSeal(['issued_at' => $payload['issued_at'] + 60] + $payload)],
        ]);

        it('binds the confirmation to its customer: another signed-in customer cannot use it', function () {
            $plan = ibtPlan(RecipientType::Bvn);
            $owner = puxCustomer(100_000);
            $thief = puxCustomer(100_000);
            $sealed = ibtConfirm($this->actingAs($owner), $plan, ibtNumber())->viewData('confirmation');

            $this->actingAs($thief)->post('/buy/bvn', ['confirmation' => $sealed, 'consent' => '1'])->assertRedirect('/buy/bvn')
                ->assertSessionHasErrors(['purchase' => IBT_INVALID_CONFIRMATION]);
            $this->post('/buy/nin', ['confirmation' => $sealed, 'consent' => '1'])->assertRedirect('/buy/nin')
                ->assertSessionHasErrors(['purchase' => IBT_INVALID_CONFIRMATION]);

            ibtNothingBought();
            expect(ibtBalance($owner))->toBe(100_000)->and(ibtBalance($thief))->toBe(100_000);
        });

        it('turns a repeated submission of one confirmation into one purchase, one debit and one provider call', function () {
            $plan = ibtPlan(RecipientType::Nin);
            $user = puxCustomer(100_000);
            FakeProvider::$purchaseScript = ['succeeded', 'succeeded'];
            FakeProvider::$resultScript = [FakeProvider::fixtureFields(), FakeProvider::fixtureFields()];
            $this->actingAs($user);
            $form = ['confirmation' => ibtConfirm($this, $plan, ibtNumber())->viewData('confirmation'), 'consent' => '1'];

            $first = $this->post('/buy/nin', $form);
            $second = $this->post('/buy/nin', $form);

            $purchase = Purchase::sole();
            $first->assertRedirect(route('purchases.show', $purchase->reference));
            $second->assertRedirect(route('purchases.show', $purchase->reference));
            expect(Transaction::where('type', 'purchase')->count())->toBe(1)
                ->and(FakeProvider::$calls)->toHaveCount(1)
                ->and(ibtBalance($user))->toBe(85_000);
            ibtClean();
        });

        it('refuses when the price changed after confirmation, or the confirmed amount is not the price', function () {
            $plan = ibtPlan(RecipientType::Nin);
            $user = puxCustomer(100_000);
            $this->actingAs($user);
            $sealed = ibtConfirm($this, $plan, ibtNumber())->viewData('confirmation');
            $payload = json_decode(Crypt::decryptString($sealed), true);

            $this->post('/buy/nin', ['confirmation' => ibtSeal(['amount' => 1] + $payload), 'consent' => '1'])->assertRedirect('/buy/nin')
                ->assertSessionHasErrors(['purchase' => 'The price has changed. Please review the new price and confirm again.']);
            PlanPrice::where('plan_id', $plan->id)->update(['price_kobo' => 20_000]);
            $this->post('/buy/nin', ['confirmation' => $sealed, 'consent' => '1'])->assertRedirect('/buy/nin')
                ->assertSessionHasErrors(['purchase' => 'The price has changed. Please review the new price and confirm again.']);

            ibtNothingBought();
            expect(ibtBalance($user))->toBe(100_000);
        });

        it('refuses a plan that stopped being purchasable after confirmation', function (Closure $break) {
            $plan = ibtPlan(RecipientType::Bvn);
            $user = puxCustomer(100_000);
            $this->actingAs($user);
            $sealed = ibtConfirm($this, $plan, ibtNumber())->viewData('confirmation');
            $break($plan);

            $this->post('/buy/bvn', ['confirmation' => $sealed, 'consent' => '1'])->assertRedirect('/buy/bvn')->assertSessionHasErrors('purchase');

            ibtNothingBought();
            expect(ibtBalance($user))->toBe(100_000);
        })->with([
            'plan inactive' => fn (Plan $plan) => $plan->forceFill(['is_active' => false])->save(),
            'no executable route' => fn (Plan $plan) => $plan->providerRoutes()->update(['is_active' => false]),
            'adapter removed' => fn () => config(['providers.drivers' => []]),
            'made variable' => fn (Plan $plan) => $plan->forceFill(['amount_type' => 'variable', 'min_amount_kobo' => 5_000, 'max_amount_kobo' => 500_000])->save(),
        ]);

        it('takes everything from the sealed confirmation, ignoring client-sent numbers, plans, prices, customers and statuses', function () {
            $plan = ibtPlan(RecipientType::Nin);
            $dearer = ibtPlan(RecipientType::Nin, [], 30_000);
            $victim = puxCustomer(100_000);
            $user = puxCustomer(100_000);
            $number = ibtNumber();
            $other = ibtNumber();
            FakeProvider::$purchaseScript = ['timeout'];
            $this->actingAs($user);
            $sealed = ibtConfirm($this, $plan, $number)->viewData('confirmation');

            $this->post('/buy/nin', ['confirmation' => $sealed, 'consent' => '1', 'identity_number' => $other, 'number' => $other, 'plan' => $dearer->id,
                'amount' => 1, 'amount_kobo' => 1, 'confirmed_amount_kobo' => 1, 'customer' => $victim->id, 'user_id' => $victim->id,
                'status' => 'successful', 'token' => (string) Str::uuid(), 'service' => 'bvn'])->assertRedirect();

            $purchase = Purchase::sole();
            expect($purchase->user_id)->toBe($user->id)
                ->and($purchase->plan_id)->toBe($plan->id)
                ->and($purchase->amount_kobo)->toBe(15_000)
                ->and($purchase->status)->toBe(PurchaseStatus::Pending)
                ->and($purchase->recipient_type)->toBe(RecipientType::Nin)
                ->and($purchase->identityRecipient->number(RecipientType::Nin))->toBe($number)
                ->and(ibtBalance($victim))->toBe(100_000);
        });
    });

    describe('confirmation expiry', function () {
        it('can be paid for exactly 10 minutes after the confirmation page', function (int $seconds, bool $paid) {
            $this->freezeTime();
            $plan = ibtPlan(RecipientType::Nin);
            $user = puxCustomer(100_000);
            FakeProvider::$purchaseScript = ['timeout'];
            $this->actingAs($user);
            $sealed = ibtConfirm($this, $plan, ibtNumber())->assertOk()->viewData('confirmation');

            $this->travel($seconds)->seconds();
            $response = $this->post('/buy/nin', ['confirmation' => $sealed, 'consent' => '1']);

            if ($paid) {
                $response->assertRedirect(route('purchases.show', Purchase::sole()->reference));
                expect(ibtBalance($user))->toBe(85_000);
            } else {
                $response->assertRedirect('/buy/nin')->assertSessionHasErrors(['purchase' => IBT_EXPIRED_CONFIRMATION]);
                ibtNothingBought();
                expect(ibtBalance($user))->toBe(100_000);
            }
        })->with([
            'at once' => [0, true],
            'after 9 minutes 59 seconds' => [599, true],
            'after exactly 10 minutes' => [600, false],
            'after an hour' => [3_600, false],
        ]);

        it('refuses an expired confirmation with a neutral message and keeps the number out of the session, the address and the logs', function () {
            config(['session.driver' => 'database']);
            $logs = ibtRecordLogs();
            $plan = ibtPlan(RecipientType::Bvn);
            $user = puxCustomer(100_000);
            $number = ibtNumber();
            $this->actingAs($user);
            $sealed = ibtConfirm($this, $plan, $number)->viewData('confirmation');

            $this->travel(10)->minutes();
            $response = $this->post('/buy/bvn', ['confirmation' => $sealed, 'consent' => '1']);

            $response->assertRedirect('/buy/bvn')->assertSessionHasErrors(['purchase' => IBT_EXPIRED_CONFIRMATION]);
            $page = $this->get('/buy/bvn')->assertOk()->assertSee(IBT_EXPIRED_CONFIRMATION)->getContent();
            ibtNothingBought();
            expect(ibtBalance($user))->toBe(100_000)
                ->and($response->headers->get('Location'))->not->toContain($number)
                ->and($page)->not->toContain($number)
                ->and(json_encode(session()->all()))->not->toContain($number)
                ->and(DB::table('sessions')->get()->map(fn ($row) => base64_decode($row->payload))->implode("\n"))->not->toContain($number)
                ->and(implode("\n", $logs->getArrayCopy()))->not->toContain($number);
        });

        it('keeps the first 10 minutes when the confirmation is shown again for the consent box', function () {
            $this->freezeTime();
            $plan = ibtPlan(RecipientType::Nin);
            $user = puxCustomer(100_000);
            $this->actingAs($user);
            $sealed = ibtConfirm($this, $plan, ibtNumber())->viewData('confirmation');

            $this->travel(5)->minutes();
            $again = $this->post('/buy/nin', ['confirmation' => $sealed])->assertStatus(422)->viewData('confirmation');
            $this->travel(5)->minutes();

            $this->post('/buy/nin', ['confirmation' => $again, 'consent' => '1'])->assertRedirect('/buy/nin')
                ->assertSessionHasErrors(['purchase' => IBT_EXPIRED_CONFIRMATION]);
            ibtNothingBought();
            expect(json_decode(Crypt::decryptString($again), true)['issued_at'])->toBe(json_decode(Crypt::decryptString($sealed), true)['issued_at']);
        });

        it('stays bound to its customer: another customer gets "no longer valid", even once it has expired', function () {
            $plan = ibtPlan(RecipientType::Bvn);
            $owner = puxCustomer(100_000);
            $thief = puxCustomer(100_000);
            $sealed = ibtConfirm($this->actingAs($owner), $plan, ibtNumber())->viewData('confirmation');

            $this->actingAs($thief)->post('/buy/bvn', ['confirmation' => $sealed, 'consent' => '1'])
                ->assertSessionHasErrors(['purchase' => IBT_INVALID_CONFIRMATION]);
            $this->travel(11)->minutes();
            $this->post('/buy/bvn', ['confirmation' => $sealed, 'consent' => '1'])->assertSessionHasErrors(['purchase' => IBT_INVALID_CONFIRMATION]);
            $this->actingAs($owner)->post('/buy/bvn', ['confirmation' => $sealed, 'consent' => '1'])
                ->assertSessionHasErrors(['purchase' => IBT_EXPIRED_CONFIRMATION]);

            ibtNothingBought();
            expect(ibtBalance($owner))->toBe(100_000)->and(ibtBalance($thief))->toBe(100_000);
        });

        it('leaves Data and Airtime confirmations without an expiry, as before', function () {
            $plan = puxPlan('data', 50_000);
            puxRoute($plan);
            $user = puxCustomer(200_000);
            FakeProvider::$purchaseScript = ['succeeded'];
            $this->actingAs($user);
            $confirm = $this->post('/buy/data/confirm', ['plan' => $plan->id, 'phone' => '08012345678'])->assertOk();

            $this->travel(1)->hours();
            $this->post('/buy/data', ['plan' => $plan->id, 'phone' => '08012345678', 'confirmed_amount_kobo' => $confirm->viewData('quote')->amountKobo,
                'token' => $confirm->viewData('token')])->assertRedirect(route('purchases.show', Purchase::sole()->reference));

            expect(Purchase::sole()->status)->toBe(PurchaseStatus::Successful)->and(ibtBalance($user))->toBe(150_000);
        });
    });

    describe('maintenance mode', function () {
        it('offers nothing and refuses new NIN/BVN purchases, while a repeated submission still finds its purchase', function () {
            $plan = ibtPlan(RecipientType::Nin);
            $user = puxCustomer(100_000);
            FakeProvider::$purchaseScript = ['timeout'];
            $this->actingAs($user);
            $bought = ibtConfirm($this, $plan, ibtNumber())->viewData('confirmation');
            $this->post('/buy/nin', ['confirmation' => $bought, 'consent' => '1']);
            $before = Purchase::sole();
            $waiting = ibtConfirm($this, $plan, ibtNumber())->viewData('confirmation');

            app(SettingsStore::class)->set(MaintenanceMode::SETTING, true);

            $this->get('/buy/nin')->assertOk()->assertSee('data-maintenance', false)->assertSee(MaintenanceMode::MESSAGE)
                ->assertDontSee('data-buy-form', false)->assertDontSee('identity_number', false);
            $this->get('/buy')->assertOk()->assertSee('data-maintenance', false)->assertDontSee('data-buy-service="nin"', false);
            $this->post('/buy/nin/confirm', ['plan' => $plan->id, 'identity_number' => ibtNumber()])->assertRedirect('/buy/nin')
                ->assertSessionHasErrors(['purchase' => MaintenanceMode::MESSAGE]);
            $this->post('/buy/nin', ['confirmation' => $waiting, 'consent' => '1'])->assertRedirect('/buy/nin')
                ->assertSessionHasErrors(['purchase' => MaintenanceMode::MESSAGE]);
            $this->post('/buy/nin', ['confirmation' => $bought, 'consent' => '1'])->assertRedirect(route('purchases.show', $before->reference));

            expect(Purchase::count())->toBe(1)
                ->and(Transaction::where('type', 'purchase')->count())->toBe(1)
                ->and(FakeProvider::$calls)->toHaveCount(1)
                ->and(ibtBalance($user))->toBe(85_000);
        });
    });

    describe('rate limits', function () {
        it('limits NIN/BVN confirmations and payments per customer on their own budgets, leaving the Data and Airtime ones unchanged', function () {
            $nin = ibtPlan(RecipientType::Nin);
            $bvn = ibtPlan(RecipientType::Bvn);
            $data = puxPlan('data', 50_000);
            puxRoute($data);
            $user = puxCustomer();
            $this->actingAs($user);

            for ($i = 0; $i < 30; $i++) {
                ibtConfirm($this, $i % 2 === 0 ? $nin : $bvn, ibtNumber())->assertOk();
            }
            ibtConfirm($this, $nin, ibtNumber())->assertStatus(429);
            ibtConfirm($this, $bvn, ibtNumber())->assertStatus(429);
            $this->post('/buy/data/confirm', ['plan' => $data->id, 'phone' => '08012345678'])->assertOk();

            for ($i = 0; $i < 10; $i++) {
                $this->post($i % 2 === 0 ? '/buy/nin' : '/buy/bvn', [])->assertRedirect();
            }
            $this->post('/buy/nin', [])->assertStatus(429);
            $this->post('/buy/data', ['plan' => $data->id])->assertRedirect();

            // The other way round: a used-up Data budget leaves NIN/BVN untouched.
            $this->actingAs(puxCustomer());
            for ($i = 0; $i < 30; $i++) {
                $this->post('/buy/data/confirm', ['plan' => $data->id, 'phone' => '08012345678'])->assertOk();
            }
            $this->post('/buy/data/confirm', ['plan' => $data->id, 'phone' => '08012345678'])->assertStatus(429);
            ibtConfirm($this, $nin, ibtNumber())->assertOk();
            expect(Purchase::count())->toBe(0);

            $limit = fn (string $name) => (fn ($limit) => [$limit->maxAttempts, $limit->decaySeconds])(
                RateLimiter::limiter($name)(Request::create('/')->setUserResolver(fn () => $user)));
            expect($limit('buy-confirm'))->toBe([30, 60])
                ->and($limit('buy-store'))->toBe([10, 60])
                ->and($limit('identity-confirm'))->toBe([30, 60])
                ->and($limit('identity-store'))->toBe([10, 60]);
        });
    });

    describe('result page', function () {
        it('shows its owner the masked number, status, reference, amount, date and every result field, escaped, never cached', function (RecipientType $type) {
            $plan = ibtPlan($type);
            $user = puxCustomer(100_000);
            $number = ibtNumber();
            $plain = 'FIXTURE-'.Str::upper(Str::random(12));
            FakeProvider::$purchaseScript = ['succeeded'];
            FakeProvider::$resultScript = [new ProviderResultFields([
                ['key' => 'fixture_1', 'label' => 'Fixture <b>one</b>', 'value' => '<script>alert("FIXTURE")</script> & \'quoted\''],
                ['key' => 'fixture_2', 'label' => 'Fixture two', 'value' => $plain],
                ['key' => 'fixture_3', 'label' => 'Fixture three', 'value' => 'fixture '.$number], // echoes the number: stored masked
            ])];
            $this->actingAs($user);
            ibtBuy($this, $plan, $number);
            $purchase = Purchase::sole();

            $response = $this->get(route('purchases.show', $purchase->reference))->assertOk();

            $html = $response->getContent();
            expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
                ->and($html)->toContain('<dt class="text-navy-600">'.$type->label().'</dt><dd class="mt-0.5 font-mono tabular-nums text-navy-900">'.$type->mask($number).'</dd>')
                ->and($html)->not->toContain($number)
                ->and($html)->toContain('Purchase successful')->toContain($purchase->reference)->toContain('₦150.00')
                ->and($html)->toContain($purchase->created_at->format('j M Y, H:i'))
                ->and($html)->toContain('data-result-field="fixture_1"><dt class="text-navy-600">Fixture &lt;b&gt;one&lt;/b&gt;</dt>')
                ->and($html)->toContain('&lt;script&gt;alert(&quot;FIXTURE&quot;)&lt;/script&gt; &amp; &#039;quoted&#039;')
                ->and($html)->not->toContain('<script>alert(')->not->toContain('<b>one</b>')
                ->and($html)->toContain('data-result-field="fixture_2"')->toContain($plain)
                ->and($html)->toContain('fixture '.$type->mask($number));
        })->with('ibt identity types');

        it('shows a neutral note when the result cannot be read, and nothing of it', function () {
            $plan = ibtPlan(RecipientType::Nin);
            $user = puxCustomer(100_000);
            $fields = FakeProvider::fixtureFields(3);
            FakeProvider::$purchaseScript = ['succeeded'];
            FakeProvider::$resultScript = [$fields];
            $this->actingAs($user);
            ibtBuy($this, $plan, ibtNumber());
            DB::table('purchase_results')->update(['encrypted_fields' => Crypt::encryptString('damaged')]);

            $response = $this->get(route('purchases.show', Purchase::sole()->reference))->assertOk()
                ->assertSee('The result for this purchase can’t be shown right now.')->assertSee('data-result-unavailable', false)
                ->assertDontSee('data-result-fields', false);

            expect($response->headers->get('Cache-Control'))->toBe('no-store, private');
            foreach ($fields->all() as $field) {
                expect($response->getContent())->not->toContain($field['value']);
            }
        });

        it('shows no result section until the purchase succeeds', function (array $script, string $heading) {
            $plan = ibtPlan(RecipientType::Bvn);
            $user = puxCustomer(100_000);
            $number = ibtNumber();
            FakeProvider::$purchaseScript = $script;
            $this->actingAs($user);
            ibtBuy($this, $plan, $number);

            $response = $this->get(route('purchases.show', Purchase::sole()->reference))->assertOk()->assertSee($heading)
                ->assertSee(RecipientType::Bvn->mask($number))->assertDontSee('data-result-section', false);

            expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
                ->and($response->getContent())->not->toContain($number);
        })->with([
            'pending' => [['timeout'], 'Purchase pending'],
            'failed' => [['failed_definite'], 'Purchase not completed'],
        ]);

        it('returns 404 to another customer and shows them nothing of it anywhere', function () {
            $plan = ibtPlan(RecipientType::Nin);
            $owner = puxCustomer(100_000);
            $other = puxCustomer(100_000);
            $number = ibtNumber();
            $fields = FakeProvider::fixtureFields(2);
            FakeProvider::$purchaseScript = ['succeeded'];
            FakeProvider::$resultScript = [$fields];
            ibtBuy($this->actingAs($owner), $plan, $number);
            $purchase = Purchase::sole();

            $this->actingAs($other)->get(route('purchases.show', $purchase->reference))->assertNotFound();
            $pages = $this->get('/purchases')->assertOk()->getContent().$this->get('/dashboard')->assertOk()->getContent()
                .$this->get('/wallet')->assertOk()->getContent();
            foreach ([$purchase->reference, RecipientType::Nin->mask($number), $number, ...array_column($fields->all(), 'value')] as $needle) {
                expect($pages)->not->toContain($needle);
            }
        });

        it('needs a signed-in customer: guests and staff are sent to sign in', function () {
            $plan = ibtPlan(RecipientType::Nin);
            FakeProvider::$purchaseScript = ['succeeded'];
            FakeProvider::$resultScript = [FakeProvider::fixtureFields()];
            $purchase = puxService()->purchase(puxCustomer(100_000), $plan, ibtNumber(), null, (string) Str::uuid(), null, true);

            foreach (['/buy/nin', '/buy/bvn', route('purchases.show', $purchase->reference)] as $url) {
                $this->get($url)->assertRedirect(route('login'));
            }
            $this->post('/buy/nin/confirm', ['plan' => $plan->id, 'identity_number' => ibtNumber()])->assertRedirect(route('login'));
            $this->post('/buy/nin', ['consent' => '1'])->assertRedirect(route('login'));
            $this->actingAs(ibtStaff(), 'admin')->get(route('purchases.show', $purchase->reference))->assertRedirect(route('login'));
            $this->get('/buy/nin')->assertRedirect(route('login'));
            expect(Purchase::count())->toBe(1);
        });

        it('keeps phone purchase result pages as in Phase 10', function () {
            $plan = puxPlan('data', 50_000);
            puxRoute($plan);
            $user = puxCustomer();
            FakeProvider::$purchaseScript = ['succeeded'];
            $purchase = puxService()->purchase($user, $plan, '08012345678', null, (string) Str::uuid());

            $response = $this->actingAs($user)->get(route('purchases.show', $purchase->reference))->assertOk();

            expect($response->headers->get('Cache-Control'))->toBe('no-cache, private')
                ->and($response->getContent())->toContain('<dt class="text-navy-600">Recipient</dt><dd class="mt-0.5 font-mono tabular-nums text-navy-900">08012345678</dd>')
                ->not->toContain('data-result-section');
        });
    });

    describe('history and dashboard', function () {
        it('show only the masked number, never a result value, and Data/Airtime rows as before', function () {
            $nin = ibtPlan(RecipientType::Nin);
            $bvn = ibtPlan(RecipientType::Bvn);
            $data = puxPlan('data', 50_000);
            $data->product->forceFill(['network' => 'mtn', 'name' => 'MTN'])->save();
            puxRoute($data);
            $user = puxCustomer(300_000);
            [$ninNumber, $bvnNumber] = [ibtNumber(), ibtNumber()];
            $fields = FakeProvider::fixtureFields(3);
            $this->actingAs($user);
            FakeProvider::$purchaseScript = ['succeeded'];
            FakeProvider::$resultScript = [$fields];
            ibtBuy($this, $nin, $ninNumber);
            FakeProvider::$purchaseScript = ['timeout'];
            ibtBuy($this, $bvn, $bvnNumber);
            FakeProvider::$purchaseScript = ['succeeded'];
            puxService()->purchase($user, $data->fresh(), '08012345678', null, (string) Str::uuid());

            foreach (['/purchases', '/dashboard'] as $url) {
                $html = $this->get($url)->assertOk()->getContent();
                expect($html)->toContain('Test product · <span class="tabular-nums">'.RecipientType::Nin->mask($ninNumber).'</span>')
                    ->and($html)->toContain('Test product · <span class="tabular-nums">'.RecipientType::Bvn->mask($bvnNumber).'</span>')
                    ->and($html)->toContain('MTN · <span class="tabular-nums">08012345678</span>')
                    ->and($html)->not->toContain($ninNumber)->not->toContain($bvnNumber)->not->toContain('data-result-section');
                foreach ($fields->all() as $field) {
                    expect($html)->not->toContain($field['value']);
                }
            }
            $wallet = $this->get('/wallet')->assertOk()->getContent();
            expect($wallet)->not->toContain($ninNumber)->not->toContain($bvnNumber)->not->toContain($fields->all()[0]['value']);
            ibtClean();
        });
    });

    describe('security', function () {
        it('keeps the number out of the session (even the database session store), every URL and the logs', function () {
            config(['session.driver' => 'database']);
            $logs = ibtRecordLogs();
            $plan = ibtPlan(RecipientType::Nin);
            $user = puxCustomer(100_000);
            $number = ibtNumber();
            $html = [];
            $locations = [];
            $this->actingAs($user);

            $response = $this->from('/buy/nin')->post('/buy/nin/confirm', ['plan' => 0, 'identity_number' => $number]); // a valid number, a refused plan
            $locations[] = $response->headers->get('Location');
            $html[] = $this->get('/buy/nin')->getContent();
            $confirm = ibtConfirm($this, $plan, $number)->assertOk();
            $html[] = $confirm->getContent();
            $html[] = $this->post('/buy/nin', ['confirmation' => $confirm->viewData('confirmation')])->assertStatus(422)->getContent();
            FakeProvider::$purchaseScript = ['succeeded'];
            FakeProvider::$resultScript = [FakeProvider::fixtureFields()];
            $response = $this->post('/buy/nin', ['confirmation' => $confirm->viewData('confirmation'), 'consent' => '1']);
            $locations[] = $response->headers->get('Location');
            foreach ([$locations[1], '/purchases', '/dashboard', '/wallet', '/buy'] as $url) {
                $html[] = $this->get($url)->assertOk()->getContent();
            }

            $sessions = DB::table('sessions')->get();
            expect($sessions)->not->toBeEmpty()
                ->and(Purchase::sole()->status)->toBe(PurchaseStatus::Successful)
                ->and($sessions->toJson())->not->toContain($number)
                ->and($sessions->map(fn ($row) => base64_decode($row->payload))->implode("\n"))->not->toContain($number)
                ->and(json_encode(session()->all()))->not->toContain($number)
                ->and(implode("\n", $logs->getArrayCopy()))->not->toContain($number)
                ->and(implode("\n", $locations))->not->toContain($number);
            foreach ($html as $page) {
                expect(ibtUrls($page))->each->not->toContain($number);
            }
        });

        it('never flashes the number back: identity_number is in dontFlash with the earlier fields', function () {
            $dontFlash = (fn () => $this->dontFlash)->call(app(ExceptionHandler::class));

            expect($dontFlash)->toContain('identity_number')->toContain('credentials')->toContain('password')->toContain('current_password')
                ->toContain('password_confirmation');
        });

        it('has POST-only NIN/BVN purchase routes with nothing in their addresses, and no form that refills the number', function () {
            $routes = collect(Route::getRoutes())->filter(fn ($route) => preg_match('#^buy/(nin|bvn)#', $route->uri()));
            $views = collect(File::allFiles(resource_path('views')))->mapWithKeys(fn ($file) => [Str::after($file->getPathname(), base_path().'/') => $file->getContents()]);

            expect($routes->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri().' '.implode(',', $route->gatherMiddleware()))->sort()->values()->all())->toBe([
                'GET|HEAD buy/bvn web,auth:web,auth.session,verified.optional',
                'GET|HEAD buy/nin web,auth:web,auth.session,verified.optional',
                'POST buy/bvn web,auth:web,auth.session,verified.optional,throttle:identity-store',
                'POST buy/bvn/confirm web,auth:web,auth.session,verified.optional,throttle:identity-confirm',
                'POST buy/nin web,auth:web,auth.session,verified.optional,throttle:identity-store',
                'POST buy/nin/confirm web,auth:web,auth.session,verified.optional,throttle:identity-confirm',
            ])->and($routes->flatMap(fn ($route) => $route->parameterNames())->all())->toBe([])
                ->and($views->filter(fn (string $code) => preg_match("/old\(\s*['\"]identity_number/", $code))->keys()->all())->toBe([]);
            preg_match_all('/<input[^>]*name="identity_number"[^>]*>/s', $views->implode("\n"), $inputs);
            expect($inputs[0])->toHaveCount(2)->each->toContain('value=""')->toContain('autocomplete="off"');
        });
    });
});
