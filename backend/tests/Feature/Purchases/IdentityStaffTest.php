<?php

use App\Http\Middleware\RefuseIdentityNumberSearch;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Providers\Data\ProviderResultFields;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserType;
use App\Support\Purchases\IdentityHasher;
use App\Support\Purchases\RecipientType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 11 CP3: NIN/BVN purchases for staff, under the existing
 * purchases.view permission. Lists and details show only the masked number,
 * and of a result only whether it is stored and its field count, never a
 * value. The exact-match search is POSTed (never in an address), never
 * echoed, flashed, logged or stored: the staff session keeps only its keyed,
 * type-prefixed lookup hashes, for 15 minutes. Purchases run with the
 * test-only FakeProvider; numbers and result values are neutral fixtures
 * generated when the tests run (D1).
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    puxDrivers();
    FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn'];
    Http::preventStrayRequests();
});

const IST_SESSION = 'admin_purchase_identity_search';

function istStaff(SystemRole|array $roleOrPermissions = SystemRole::SuperAdmin): SystemUser
{
    $staff = SystemUser::factory()->create();
    if ($roleOrPermissions instanceof SystemRole) {
        $staff->assignRole($roleOrPermissions->value);
    } else {
        $role = Role::create(['name' => 'IST '.Str::random(5), 'guard_name' => 'admin'])->givePermissionTo(['admin.access', ...$roleOrPermissions]);
        $staff->assignRole($role->name);
    }

    return $staff;
}

function istNumber(): string
{
    return (string) random_int(10_000_000_000, 99_999_999_999);
}

/** An available fixed-price NIN or BVN plan (15,000 kobo) with an executable FakeProvider route. */
function istPlan(RecipientType $type): Plan
{
    $service = Service::where('slug', $type->value)->first() ?? Service::factory()->create(['name' => $type->label(), 'slug' => $type->value]);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Test product', 'code' => $type->value.'-test-'.Str::lower(Str::random(6))]);
    $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Test plan', 'code' => $product->code.'-p', 'amount_type' => 'fixed']);
    PlanPrice::factory()->create(['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => 15_000]);
    puxRoute($plan, 1, ['cost_type' => 'fixed', 'cost_kobo' => 10_000]);

    return $plan->fresh();
}

/** A NIN/BVN purchase through PurchaseService (consent given), with the scripted outcome and, for a success, $fields. */
function istBuy(RecipientType $type, string $number, string $outcome = 'succeeded', ?ProviderResultFields $fields = null, ?User $user = null): Purchase
{
    $plan = Plan::whereHas('product.service', fn ($service) => $service->where('slug', $type->value))->first() ?? istPlan($type);
    FakeProvider::$purchaseScript = [$outcome];
    FakeProvider::$resultScript = $outcome === 'succeeded' ? [$fields ?? FakeProvider::fixtureFields()] : [];

    return puxService()->purchase($user ?? puxCustomer(100_000), $plan, $number, null, (string) Str::uuid(), null, true);
}

function istSearch($test, string $type, mixed $number)
{
    return $test->post('/admin/purchases/identity-search', ['identity_type' => $type, 'identity_number' => $number]);
}

/** The purchase references listed on an admin purchases page. */
function istListed(string $html): array
{
    preg_match_all('/data-purchase="([^"]+)"/', $html, $matches);

    return $matches[1];
}

function istUrls(string $html): array
{
    preg_match_all('/(?:href|action|src|formaction)="([^"]*)"/', $html, $matches);

    return $matches[1];
}

describe('list and detail', function () {
    it('show NIN/BVN purchases with the masked number only, and of a result only whether it is stored and its field count', function () {
        $staff = istStaff();
        [$ninNumber, $bvnNumber] = [istNumber(), istNumber()];
        $fields = FakeProvider::fixtureFields(3);
        $delivered = istBuy(RecipientType::Nin, $ninNumber, 'succeeded', $fields);
        $failed = istBuy(RecipientType::Bvn, $bvnNumber, 'failed_definite');
        $plan = puxPlan('data', 50_000);
        puxRoute($plan);
        FakeProvider::$purchaseScript = ['succeeded'];
        $phone = puxService()->purchase(puxCustomer(), $plan, '08012345678', null, (string) Str::uuid());
        $this->actingAs($staff, 'admin');

        $list = $this->get('/admin/purchases')->assertOk()->getContent();
        $shown = $this->get("/admin/purchases/{$delivered->id}")->assertOk();
        $failedPage = $this->get("/admin/purchases/{$failed->id}")->assertOk()->getContent();
        $phonePage = $this->get("/admin/purchases/{$phone->id}")->assertOk()->getContent();

        expect($list)->toContain('→ <span class="tabular-nums">'.RecipientType::Nin->mask($ninNumber).'</span>')
            ->and($list)->toContain('→ <span class="tabular-nums">'.RecipientType::Bvn->mask($bvnNumber).'</span>')
            ->and($list)->toContain('→ <span class="tabular-nums">08012345678</span>')
            ->and($shown->getContent())->toContain('<dt class="text-navy-600">NIN</dt><dd class="mt-0.5 font-mono tabular-nums text-navy-900" data-recipient>'.RecipientType::Nin->mask($ninNumber).'</dd>')
            ->and($shown->getContent())->toContain('data-result-summary>Stored · 3 fields (shown to the customer only)</dd>')
            ->and($shown->viewData('resultFieldCount'))->toBe(3)
            ->and($shown->original->getData())->not->toHaveKey('resultFields')
            ->and($shown->viewData('purchase')->relationLoaded('result'))->toBeFalse()
            ->and($shown->viewData('purchase')->identityRecipient->getAttributes())->toHaveKeys(['id', 'purchase_id', 'masked_value'])
            ->and(array_keys($shown->viewData('purchase')->identityRecipient->getAttributes()))->toHaveCount(3)
            ->and($failedPage)->toContain('<dt class="text-navy-600">BVN</dt>')->toContain('data-result-summary>None stored</dd>')
            ->and($phonePage)->toContain('<dt class="text-navy-600">Recipient</dt><dd class="mt-0.5 font-mono tabular-nums text-navy-900" data-recipient>08012345678</dd>')
            ->and($phonePage)->not->toContain('data-result-summary');
        $pages = $list.$shown->getContent().$failedPage;
        foreach ([$ninNumber, $bvnNumber, ...array_column($fields->all(), 'value')] as $needle) {
            expect($pages)->not->toContain($needle);
        }
    });

    it('lists NIN/BVN purchases without extra queries per row', function () {
        $staff = istStaff();
        $count = function () use ($staff) {
            $this->actingAs($staff, 'admin');
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/admin/purchases')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        foreach (range(1, 2) as $i) {
            istBuy(RecipientType::Nin, istNumber());
        }
        $count();
        $few = $count();
        foreach (range(1, 6) as $i) {
            istBuy($i % 2 ? RecipientType::Nin : RecipientType::Bvn, istNumber());
        }

        expect($count())->toBe($few);
    });
});

describe('exact-match search', function () {
    it('finds NIN purchases by the exact number, POSTed, keeping it out of the address, session, page and logs', function () {
        $this->freezeTime();
        $logs = new ArrayObject;
        Event::listen(MessageLogged::class, fn (MessageLogged $event) => $logs->append($event->message.' '.json_encode($event->context)));
        $staff = istStaff(['purchases.view']);
        [$number, $other] = [istNumber(), istNumber()];
        $first = istBuy(RecipientType::Nin, $number);
        $second = istBuy(RecipientType::Nin, $number, 'timeout');
        $different = istBuy(RecipientType::Nin, $other);
        $sameAsBvn = istBuy(RecipientType::Bvn, $number);
        $this->actingAs($staff, 'admin');

        $response = istSearch($this, 'nin', implode(' ', str_split($number, 4))); // spaces are ignored

        $response->assertRedirect('/admin/purchases?identity=1');
        $state = session(IST_SESSION);
        expect($response->headers->get('Location'))->toBe(url('/admin/purchases?identity=1'))
            ->and(array_keys($state))->toBe(['type', 'hashes', 'expires_at'])
            ->and($state['type'])->toBe('nin')
            ->and($state['hashes'])->toBe(IdentityHasher::lookupHashes(RecipientType::Nin, $number))
            ->and($state['expires_at'])->toBe(now()->addMinutes(15)->getTimestamp())
            ->and(session()->getOldInput())->toBe([])
            ->and(json_encode(session()->all()))->not->toContain($number);

        $page = $this->get('/admin/purchases?identity=1')->assertOk()->assertSee('data-identity-filter="nin"', false)
            ->assertSee('Showing NIN purchases for the number you searched for.')->getContent();
        expect(istListed($page))->toEqualCanonicalizing([$first->reference, $second->reference])
            ->and($page)->not->toContain($number)->toContain(RecipientType::Nin->mask($number))
            ->and(istUrls($page))->each->not->toContain($number)
            ->and(implode("\n", $logs->getArrayCopy()))->not->toContain($number);
        preg_match('/<input[^>]*name="identity_number"[^>]*>/', $page, $input);
        expect($input[0])->toContain('value=""')->toContain('autocomplete="off"')
            ->and(istListed($page))->not->toContain($different->reference)->not->toContain($sameAsBvn->reference);
        // Pages keep the search; other filters narrow it.
        expect(istListed($this->get('/admin/purchases?identity=1&status=pending')->getContent()))->toBe([$second->reference]);
    });

    it('keeps NIN and BVN apart', function () {
        $number = istNumber();
        $nin = istBuy(RecipientType::Nin, $number);
        $bvn = istBuy(RecipientType::Bvn, $number);
        $onlyBvn = istNumber();
        istBuy(RecipientType::Bvn, $onlyBvn);
        $this->actingAs(istStaff(), 'admin');

        istSearch($this, 'bvn', $number)->assertRedirect('/admin/purchases?identity=1');
        expect(istListed($this->get('/admin/purchases?identity=1')->assertSee('data-identity-filter="bvn"', false)->getContent()))->toBe([$bvn->reference]);
        istSearch($this, 'nin', $number);
        expect(istListed($this->get('/admin/purchases?identity=1')->getContent()))->toBe([$nin->reference]);
        istSearch($this, 'nin', $onlyBvn);
        $this->get('/admin/purchases?identity=1')->assertSee('No purchases found');
        expect(IdentityHasher::lookupHash(RecipientType::Nin, $number))->not->toBe(IdentityHasher::lookupHash(RecipientType::Bvn, $number));
    });

    it('matches only the exact number', function () {
        $number = istNumber();
        istBuy(RecipientType::Nin, $number);
        $this->actingAs(istStaff(), 'admin');
        $near = substr($number, 0, 10).(((int) substr($number, -1) + 1) % 10);

        istSearch($this, 'nin', $near);
        $this->get('/admin/purchases?identity=1')->assertOk()->assertSee('No purchases found')->assertSee('data-identity-filter="nin"', false);
        istSearch($this, 'nin', substr($number, -4))->assertSessionHasErrors(['identity_number' => 'Enter a valid 11-digit NIN.'], null, 'identity');
    });

    it('refuses anything but a NIN or BVN of 11 digits, never echoing or keeping it', function (string $type, Closure $input, string $field, string $message) {
        $this->actingAs(istStaff(), 'admin');
        $typed = $input();

        $response = $this->from('/admin/purchases?status=pending')->post('/admin/purchases/identity-search', ['identity_type' => $type, 'identity_number' => $typed]);

        $response->assertSessionHasErrors([$field => $message], null, 'identity');
        expect(session()->has(IST_SESSION))->toBeFalse()
            ->and(session()->getOldInput())->not->toHaveKey('identity_number')
            ->and(json_encode(session()->all()))->not->toContain(json_encode($typed));
        $page = $this->get('/admin/purchases')->assertOk()->assertSee('data-identity-search-error', false)->assertSee($message)->getContent();
        $text = is_array($typed) ? $typed[0] : $typed;
        expect($text === '' ? false : str_contains($page, 'value="'.e($text).'"'))->toBeFalse()
            ->and($page)->not->toContain('data-identity-filter')
            ->and(strlen($text) >= 10 ? str_contains($page, $text) : false)->toBeFalse();
    })->with([
        'too short' => ['nin', fn () => substr(istNumber(), 1), 'identity_number', 'Enter a valid 11-digit NIN.'],
        'last four digits' => ['bvn', fn () => substr(istNumber(), -4), 'identity_number', 'Enter a valid 11-digit BVN.'],
        'letters' => ['nin', fn () => substr(istNumber(), 1).'x', 'identity_number', 'Enter a valid 11-digit NIN.'],
        'too long' => ['nin', fn () => str_repeat(istNumber(), 3), 'identity_number', 'Enter a valid 11-digit number.'],
        'not text' => ['bvn', fn () => [istNumber()], 'identity_number', 'Enter a valid 11-digit number.'],
        'empty' => ['nin', fn () => '', 'identity_number', 'Enter the number to find.'],
        'a phone type' => ['phone', fn () => istNumber(), 'identity_type', 'Choose NIN or BVN.'],
    ]);

    it('ends the search after 15 minutes, and Clear ends it at once; an ended or broken search matches nothing', function () {
        $number = istNumber();
        $purchase = istBuy(RecipientType::Nin, $number);
        $this->actingAs(istStaff(), 'admin');

        $this->get('/admin/purchases?identity=1')->assertOk()->assertSee('data-identity-filter-ended', false)->assertSee('No purchases found');
        istSearch($this, 'nin', $number);
        $this->travel(14)->minutes();
        expect(istListed($this->get('/admin/purchases?identity=1')->getContent()))->toBe([$purchase->reference]);
        $this->travel(2)->minutes();
        $this->get('/admin/purchases?identity=1')->assertSee('data-identity-filter-ended', false)->assertSee('No purchases found');
        expect(session()->has(IST_SESSION))->toBeFalse();

        istSearch($this, 'nin', $number);
        $this->post('/admin/purchases/identity-search/clear')->assertRedirect('/admin/purchases');
        expect(session()->has(IST_SESSION))->toBeFalse();
        $this->get('/admin/purchases?identity=1')->assertSee('data-identity-filter-ended', false)->assertSee('No purchases found');
        expect(istListed($this->get('/admin/purchases')->getContent()))->toBe([$purchase->reference]);

        foreach ([['type' => 'phone'], ['hashes' => 'x'], ['hashes' => []], ['expires_at' => (string) now()->addHour()->getTimestamp()], ['type' => null]] as $broken) {
            istSearch($this, 'nin', $number);
            session()->put(IST_SESSION, $broken + session(IST_SESSION));
            $this->get('/admin/purchases?identity=1')->assertSee('No purchases found');
            expect(session()->has(IST_SESSION))->toBeFalse();
        }
        $this->get('/admin/purchases?identity=2')->assertSessionHasErrors('identity');
    });

    it('finds purchases made under the previous app key', function () {
        $number = istNumber();
        $purchase = istBuy(RecipientType::Bvn, $number);
        $oldKey = config('app.key');
        $useKeys = function (string $key, array $previous) {
            config(['app.key' => $key, 'app.previous_keys' => $previous]);
            app()->forgetInstance('encrypter');
            Crypt::clearResolvedInstance('encrypter');
        };
        $this->actingAs(istStaff(), 'admin');

        $useKeys('base64:'.base64_encode(Encrypter::generateKey(config('app.cipher'))), [$oldKey]);
        istSearch($this, 'bvn', $number);
        expect(istListed($this->get('/admin/purchases?identity=1')->getContent()))->toBe([$purchase->reference]);

        $useKeys(config('app.key'), []); // the old key dropped from APP_PREVIOUS_KEYS
        istSearch($this, 'bvn', $number);
        $this->get('/admin/purchases?identity=1')->assertSee('No purchases found');
    });

    it('has no GET search: the number can never be sent in an address', function () {
        $number = istNumber();
        $purchase = istBuy(RecipientType::Nin, $number);
        $this->actingAs(istStaff(), 'admin');

        $this->get('/admin/purchases/identity-search')->assertStatus(405);
        expect(istListed($this->get('/admin/purchases?identity_type=nin&identity_number='.$number)->getContent()))->toBe([$purchase->reference]); // ignored
        $this->get('/admin/purchases?q='.$number)->assertRedirect('/admin/purchases'); // the ordinary search box refuses a NIN/BVN (below)
        expect(session()->has(IST_SESSION))->toBeFalse()
            ->and(collect(Route::getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'identity-search'))
                ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())->sort()->values()->all())
            ->toBe(['POST admin/purchases/identity-search', 'POST admin/purchases/identity-search/clear']);
    });
});

describe('ordinary GET search', function () {
    it('refuses a NIN/BVN-shaped term without searching, echoing, logging or keeping it, and points to the NIN/BVN search', function (Closure $query) {
        config(['session.driver' => 'database']);
        $logs = new ArrayObject;
        Event::listen(MessageLogged::class, fn (MessageLogged $event) => $logs->append($event->message.' '.json_encode($event->context)));
        $number = istNumber();
        $nin = istBuy(RecipientType::Nin, $number, 'timeout');
        $plan = puxPlan('data', 50_000);
        puxRoute($plan);
        FakeProvider::$purchaseScript = ['succeeded'];
        $phone = puxService()->purchase(puxCustomer(), $plan, '08012345678', null, (string) Str::uuid());
        $this->actingAs(istStaff(), 'admin');

        $response = $this->get('/admin/purchases?status=pending&'.$query($number));

        $response->assertRedirect('/admin/purchases');
        expect($response->headers->get('Location'))->toBe(url('/admin/purchases'))
            ->and(session()->previousUrl())->toBe(url('/admin/purchases'))
            ->and(session()->getOldInput())->toBe([])
            ->and(json_encode(session()->all()))->not->toContain($number);
        $page = $this->get('/admin/purchases')->assertOk()->assertSee(RefuseIdentityNumberSearch::MESSAGE)->getContent();
        preg_match('/<input id="q"[^>]*>/', $page, $input);
        expect($input[0])->toContain('value=""')
            ->and(istListed($page))->toEqualCanonicalizing([$nin->reference, $phone->reference]) // the plain list: nothing was searched
            ->and($page)->not->toContain($number)
            ->and(istUrls($page))->each->not->toContain($number)
            ->and(implode("\n", $logs->getArrayCopy()))->not->toContain($number)
            ->and(DB::table('admin_sessions')->get()->map(fn ($row) => base64_decode($row->payload))->implode("\n"))->not->toContain($number)
            ->and(DB::table('sessions')->get()->map(fn ($row) => base64_decode($row->payload))->implode("\n"))->not->toContain($number);
    })->with([
        '11 digits' => [fn (string $n) => 'q='.$n],
        'spaced' => [fn (string $n) => 'q='.urlencode(implode(' ', str_split($n, 4)))],
        'padded' => [fn (string $n) => 'q='.urlencode('  '.$n.' ')],
        'in a list' => [fn (string $n) => 'q[]='.$n],
    ]);

    it('refuses it before sign-in, so a signed-out visitor\'s intended address never holds the number', function () {
        $number = istNumber();

        $this->get('/admin/purchases?q='.$number)->assertRedirect('/admin/purchases');
        expect(json_encode(session()->all()))->not->toContain($number);
        $this->get('/admin/purchases')->assertRedirect(route('admin.login'));

        expect(session('url.intended'))->toBe(url('/admin/purchases'))
            ->and(json_encode(session()->all()))->not->toContain($number);
    });

    it('keeps phone, reference, customer and every other search exactly as before', function () {
        $plan = puxPlan('data', 50_000);
        puxRoute($plan);
        FakeProvider::$purchaseScript = ['succeeded', 'succeeded'];
        $user = puxCustomer();
        $user->forceFill(['name' => 'Ada Buyer', 'email' => 'ada.buyer@example.test'])->save();
        $ada = puxService()->purchase($user, $plan, '08011112222', null, (string) Str::uuid());
        $other = puxService()->purchase(puxCustomer(), $plan, '08033334444', null, (string) Str::uuid());
        $this->actingAs(istStaff(), 'admin');

        foreach (['08011112222', '0801 111 2222', '+2348011112222', '0801111', $ada->reference, 'Ada Buy', 'ada.buyer@'] as $term) {
            $page = $this->get('/admin/purchases?q='.urlencode($term))->assertOk()->assertDontSee(RefuseIdentityNumberSearch::MESSAGE);
            expect(istListed($page->getContent()))->toBe([$ada->reference], $term);
        }
        foreach ([substr(istNumber(), 1), istNumber().'1', 'text '.istNumber(), istNumber().'x', '1234-5678-901', '2348011112222'] as $term) {
            $this->get('/admin/purchases?q='.urlencode($term))->assertOk()->assertDontSee(RefuseIdentityNumberSearch::MESSAGE)->assertSee('No purchases found');
        }
        expect(istListed($this->get('/admin/purchases?q='.urlencode('080'))->getContent()))->toEqualCanonicalizing([$ada->reference, $other->reference]);
    });

    it('sees a NIN/BVN-shaped term only when it is not an accepted phone number', function () {
        $number = istNumber();

        expect(RefuseIdentityNumberSearch::looksLikeIdentityNumber($number))->toBeTrue()
            ->and(RefuseIdentityNumberSearch::looksLikeIdentityNumber(implode(' ', str_split($number, 3))))->toBeTrue()
            ->and(RefuseIdentityNumberSearch::looksLikeIdentityNumber([[$number]]))->toBeTrue()
            ->and(RefuseIdentityNumberSearch::looksLikeIdentityNumber('0'.substr($number, 1)))->toBeFalse() // also a local phone number: searched as one
            ->and(RefuseIdentityNumberSearch::looksLikeIdentityNumber(substr($number, 1)))->toBeFalse()
            ->and(RefuseIdentityNumberSearch::looksLikeIdentityNumber($number.'0'))->toBeFalse()
            ->and(RefuseIdentityNumberSearch::looksLikeIdentityNumber(implode('-', str_split($number, 4))))->toBeFalse() // outside the 11-digit rule
            ->and(RefuseIdentityNumberSearch::looksLikeIdentityNumber(null))->toBeFalse()
            ->and(RefuseIdentityNumberSearch::looksLikeIdentityNumber(''))->toBeFalse();
    });
});

describe('access', function () {
    it('uses the existing purchases.view permission for the search, with no new permission', function () {
        $number = istNumber();
        $purchase = istBuy(RecipientType::Nin, $number);

        foreach ([SystemRole::Manager, SystemRole::Finance, SystemRole::Support, SystemRole::Viewer] as $role) {
            $this->actingAs(istStaff($role), 'admin');
            istSearch($this, 'nin', $number)->assertForbidden();
            $this->post('/admin/purchases/identity-search/clear')->assertForbidden();
        }
        $this->actingAs(istStaff(['purchases.view']), 'admin');
        istSearch($this, 'nin', $number)->assertRedirect('/admin/purchases?identity=1');
        expect(istListed($this->get('/admin/purchases?identity=1')->getContent()))->toBe([$purchase->reference])
            ->and(collect(SystemPermission::cases())->map->value->filter(fn ($name) => preg_match('/identity|nin|bvn|search/i', $name))->all())->toBe([])
            ->and(Permission::where('name', 'like', '%identity%')->orWhere('name', 'like', '%nin%')->orWhere('name', 'like', '%bvn%')->count())->toBe(0)
            ->and(Route::getRoutes()->getByName('admin.purchases.identity-search')->gatherMiddleware())->toContain(SystemPermission::PurchasesView->middleware())
            ->and(Route::getRoutes()->getByName('admin.purchases.identity-search.clear')->gatherMiddleware())->toContain(SystemPermission::PurchasesView->middleware());
    });

    it('sends guests and customers to the staff sign-in', function () {
        $number = istNumber();
        $purchase = istBuy(RecipientType::Nin, $number);

        istSearch($this, 'nin', $number)->assertRedirect(route('admin.login'));
        $this->actingAs($purchase->user, 'web');
        istSearch($this, 'nin', $number)->assertRedirect(route('admin.login'));
        $this->post('/admin/purchases/identity-search/clear')->assertRedirect(route('admin.login'));
        expect(session()->has(IST_SESSION))->toBeFalse();
    });

    it('limits searches per staff member on their own budget', function () {
        $staff = istStaff();
        $this->actingAs($staff, 'admin');

        for ($i = 0; $i < 20; $i++) {
            istSearch($this, 'nin', istNumber())->assertRedirect('/admin/purchases?identity=1');
        }
        istSearch($this, 'nin', istNumber())->assertStatus(429);
        $this->post('/admin/purchases/identity-search/clear')->assertRedirect('/admin/purchases');
        $this->actingAs(istStaff(), 'admin');
        istSearch($this, 'bvn', istNumber())->assertRedirect('/admin/purchases?identity=1');

        $limit = RateLimiter::limiter('identity-search')(Request::create('/')->setUserResolver(fn () => $staff));
        expect([$limit->maxAttempts, $limit->decaySeconds])->toBe([20, 60]);
    });
});
