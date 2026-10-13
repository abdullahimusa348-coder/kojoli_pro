<?php

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
use App\Support\Purchases\PurchaseStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 11 CP4: Exam PIN purchases for staff, under the existing
 * purchases.view permission (re-check: purchases.manage). An Exam PIN purchase
 * has no recipient, so lists and details show a dash; of its result staff see
 * only whether it is stored and its field count, never a value, label or key
 * (no PIN, no serial). Search is unchanged: there is no PIN or serial search.
 * Purchases run with the test-only FakeProvider; result values are neutral
 * fixtures generated when the tests run (D1).
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    puxDrivers();
    FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn', 'exam-pin'];
    Http::preventStrayRequests();
});

function estStaff(SystemRole|array $roleOrPermissions = SystemRole::SuperAdmin): SystemUser
{
    $staff = SystemUser::factory()->create();
    if ($roleOrPermissions instanceof SystemRole) {
        $staff->assignRole($roleOrPermissions->value);
    } else {
        $role = Role::create(['name' => 'EST '.Str::random(5), 'guard_name' => 'admin'])->givePermissionTo(['admin.access', ...$roleOrPermissions]);
        $staff->assignRole($role->name);
    }

    return $staff;
}

/** An available fixed-price plan of the existing exam-pin service (15,000 kobo) with an executable FakeProvider route. */
function estPlan(): Plan
{
    $service = Service::where('slug', 'exam-pin')->first() ?? Service::factory()->create(['name' => 'Exam PIN', 'slug' => 'exam-pin']);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Fixture exam', 'code' => 'exam-pin-test-'.Str::lower(Str::random(6)),
        'network' => null]);
    $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Fixture PIN', 'code' => $product->code.'-p', 'amount_type' => 'fixed']);
    PlanPrice::factory()->create(['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => 15_000]);
    puxRoute($plan, 1, ['cost_type' => 'fixed', 'cost_kobo' => 10_000]);

    return $plan->fresh();
}

/** An Exam PIN purchase through PurchaseService, with the scripted outcome and, for a success, $fields. */
function estBuy(string $outcome = 'succeeded', ?ProviderResultFields $fields = null, ?User $user = null): Purchase
{
    $plan = Plan::whereHas('product.service', fn ($service) => $service->where('slug', 'exam-pin'))->first() ?? estPlan();
    FakeProvider::$purchaseScript = [$outcome];
    FakeProvider::$resultScript = $outcome === 'succeeded' ? [$fields ?? FakeProvider::fixtureFields()] : [];

    return puxService()->purchase($user ?? puxCustomer(100_000), $plan, '', null, (string) Str::uuid());
}

/** The purchase references listed on an admin purchases page. */
function estListed(string $html): array
{
    preg_match_all('/data-purchase="([^"]+)"/', $html, $matches);

    return $matches[1];
}

/** Every value, label and key of the delivered fields. */
function estSecrets(ProviderResultFields $fields): array
{
    return array_merge(...array_map(fn (array $field) => [$field['value'], 'data-result-field', 'data-result-fields'], $fields->all()));
}

describe('list and detail', function () {
    it('show Exam PIN purchases with a dash for the recipient and, of a result, only whether it is stored and its field count', function () {
        $staff = estStaff();
        $fields = FakeProvider::fixtureFields(2);
        $delivered = estBuy('succeeded', $fields);
        $failed = estBuy('failed_definite');
        $pending = estBuy('timeout');
        $this->actingAs($staff, 'admin');

        $list = $this->get('/admin/purchases')->assertOk()->getContent();
        $shown = $this->get("/admin/purchases/{$delivered->id}")->assertOk();
        $failedPage = $this->get("/admin/purchases/{$failed->id}")->assertOk()->getContent();
        $pendingPage = $this->get("/admin/purchases/{$pending->id}")->assertOk()->getContent();

        expect(estListed($list))->toEqualCanonicalizing([$delivered->reference, $failed->reference, $pending->reference])
            ->and(substr_count($list, '→ <span class="tabular-nums">—</span>'))->toBe(3)
            ->and($shown->getContent())->toContain('<dt class="text-navy-600">Recipient</dt><dd class="mt-0.5 font-mono tabular-nums text-navy-900" data-recipient>—</dd>')
            ->and($shown->getContent())->toContain('data-result-summary>Stored · 2 fields (shown to the customer only)</dd>')
            ->and($shown->viewData('resultFieldCount'))->toBe(2)
            ->and($shown->original->getData())->not->toHaveKey('resultFields')
            ->and($shown->viewData('purchase')->relationLoaded('result'))->toBeFalse()
            ->and($shown->viewData('purchase')->relationLoaded('identityRecipient'))->toBeFalse()
            ->and(array_keys($shown->viewData('purchase')->toArray()))->not->toContain('result')
            ->and($failedPage)->toContain('data-result-summary>None stored</dd>')
            ->and($pendingPage)->toContain('data-result-summary>None stored</dd>')->toContain('data-recheck');
        $pages = $list.$shown->getContent().$failedPage.$pendingPage;
        foreach (estSecrets($fields) as $needle) {
            expect($pages)->not->toContain($needle);
        }
    });

    it('lists Exam PIN purchases without extra queries per row', function () {
        $staff = estStaff();
        $count = function () use ($staff) {
            $this->actingAs($staff, 'admin');
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/admin/purchases')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        foreach (range(1, 2) as $i) {
            estBuy();
        }
        $count();
        $few = $count();
        foreach (range(1, 6) as $i) {
            estBuy($i % 2 ? 'succeeded' : 'timeout');
        }

        expect($count())->toBe($few);
    });

    it('never shows a result value on any staff page', function () {
        $fields = FakeProvider::fixtureFields(3);
        $purchase = estBuy('succeeded', $fields);
        $this->actingAs(estStaff(), 'admin');

        $pages = collect(['/admin', '/admin/purchases', "/admin/purchases/{$purchase->id}", "/admin/users/{$purchase->user_id}",
            "/admin/wallet/{$purchase->user_id}", '/admin/transactions', '/admin/transactions/'.$purchase->debit_transaction_id])
            ->map(fn (string $url) => $this->get($url)->assertOk()->getContent())->implode("\n");

        expect($pages)->toContain($purchase->reference);
        foreach (estSecrets($fields) as $needle) {
            expect($pages)->not->toContain($needle);
        }
    });
});

describe('search', function () {
    it('finds Exam PIN purchases by reference, customer and service as before, and never by a PIN or serial', function () {
        $user = puxCustomer();
        $user->forceFill(['name' => 'Ada Buyer', 'email' => 'ada.buyer@example.test'])->save();
        $fields = FakeProvider::fixtureFields(2);
        $purchase = estBuy('succeeded', $fields, $user);
        $this->actingAs(estStaff(), 'admin');

        foreach ([$purchase->reference, 'Ada Buy', 'ada.buyer@'] as $term) {
            expect(estListed($this->get('/admin/purchases?q='.urlencode($term))->assertOk()->getContent()))->toBe([$purchase->reference], $term);
        }
        expect(estListed($this->get('/admin/purchases?service='.$purchase->service_id)->assertOk()->getContent()))->toBe([$purchase->reference]);
        foreach (array_column($fields->all(), 'value') as $value) {
            $this->get('/admin/purchases?q='.urlencode($value))->assertOk()->assertSee('No purchases found');
        }
    });
});

describe('access', function () {
    it('uses the existing purchases.view permission, and purchases.manage for re-checks, with no new permission', function () {
        $pending = estBuy('timeout');
        $pending->forceFill(['next_check_at' => now()->subMinute()])->save();

        foreach ([SystemRole::Manager, SystemRole::Finance, SystemRole::Support, SystemRole::Viewer] as $role) {
            $this->actingAs(estStaff($role), 'admin');
            $this->get('/admin/purchases')->assertForbidden();
            $this->get("/admin/purchases/{$pending->id}")->assertForbidden();
        }
        $this->actingAs(estStaff(['purchases.view']), 'admin');
        $this->get("/admin/purchases/{$pending->id}")->assertOk()->assertDontSee('data-recheck', false);
        $this->post("/admin/purchases/{$pending->id}/recheck")->assertForbidden();

        $fields = FakeProvider::fixtureFields(2);
        FakeProvider::$queryScript = ['succeeded'];
        FakeProvider::$resultScript = [$fields];
        $this->actingAs(estStaff(['purchases.view', 'purchases.manage']), 'admin');
        $this->from("/admin/purchases/{$pending->id}")->post("/admin/purchases/{$pending->id}/recheck")
            ->assertRedirect("/admin/purchases/{$pending->id}")->assertSessionHas('status', 'Re-checked: the purchase is now Successful.');
        $page = $this->get("/admin/purchases/{$pending->id}")->assertOk()->getContent();

        expect($pending->fresh()->status)->toBe(PurchaseStatus::Successful)
            ->and($page)->toContain('data-result-summary>Stored · 2 fields (shown to the customer only)</dd>')
            ->and(collect(SystemPermission::cases())->map->value->filter(fn ($name) => preg_match('/exam|pin|result/i', $name))->all())->toBe([])
            ->and(Permission::where('name', 'like', '%exam%')->orWhere('name', 'like', '%pin%')->count())->toBe(0)
            ->and(Route::getRoutes()->getByName('admin.purchases.show')->gatherMiddleware())->toContain(SystemPermission::PurchasesView->middleware());
        foreach (estSecrets($fields) as $needle) {
            expect($page)->not->toContain($needle);
        }
    });

    it('sends guests and customers to the staff sign-in', function () {
        $purchase = estBuy();

        $this->get("/admin/purchases/{$purchase->id}")->assertRedirect(route('admin.login'));
        $this->actingAs($purchase->user, 'web');
        $this->get("/admin/purchases/{$purchase->id}")->assertRedirect(route('admin.login'));
        $this->get('/admin/purchases')->assertRedirect(route('admin.login'));
    });
});
