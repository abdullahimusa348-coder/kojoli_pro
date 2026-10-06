<?php

use App\Actions\Admin\Purchases\RecheckPurchase;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\PurchaseStatusChange;
use App\Models\SystemUser;
use App\Support\Enums\SystemRole;
use App\Support\Purchases\PurchaseSource;
use App\Support\Purchases\PurchaseStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 10 Step 1, CP5: admin Purchases module (list, filters, search,
 * detail, staff re-check). Purchases are created through PurchaseService with
 * the test-only FakeProvider.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    puxDrivers();
    Http::preventStrayRequests();
});

function pu5Staff(SystemRole|array $roleOrPermissions = SystemRole::SuperAdmin): SystemUser
{
    $staff = SystemUser::factory()->create();
    if ($roleOrPermissions instanceof SystemRole) {
        $staff->assignRole($roleOrPermissions->value);
    } else {
        $role = Role::create(['name' => 'PU5 '.Str::random(5), 'guard_name' => 'admin'])->givePermissionTo(['admin.access', ...$roleOrPermissions]);
        $staff->assignRole($role->name);
    }

    return $staff;
}

/** A purchase through the real service with the scripted FakeProvider outcome(s). */
function pu5Purchase(array $script = ['succeeded'], array $customer = [], string $phone = '08012345678', ?Plan $plan = null): Purchase
{
    if ($plan === null) {
        $plan = puxPlan();
        puxRoute($plan, 1);
        puxRoute($plan, 2, ['cost_type' => 'fixed', 'cost_kobo' => 46_000]);
    }
    $user = puxCustomer(500_000);
    if ($customer !== []) {
        $user->forceFill($customer)->save();
    }
    FakeProvider::$purchaseScript = $script;

    return puxService()->purchase($user, $plan, $phone, null, (string) Str::uuid());
}

describe('access', function () {
    it('lets Super Admin and staff with purchases.view open the list and detail', function (Closure $staff) {
        $purchase = pu5Purchase();

        $this->actingAs($staff(), 'admin')->get('/admin/purchases')->assertOk()->assertSee($purchase->reference);
        $this->get("/admin/purchases/{$purchase->id}")->assertOk()->assertSee($purchase->reference);
    })->with([
        'super admin' => fn () => pu5Staff(),
        'purchases.view role' => fn () => pu5Staff(['purchases.view']),
    ]);

    it('denies staff without purchases.view, guests and customers', function () {
        $purchase = pu5Purchase(['timeout']);

        foreach ([SystemRole::Manager, SystemRole::Finance, SystemRole::Support, SystemRole::Viewer] as $role) {
            $this->actingAs(pu5Staff($role), 'admin')->get('/admin/purchases')->assertForbidden();
            $this->get("/admin/purchases/{$purchase->id}")->assertForbidden();
            $this->post("/admin/purchases/{$purchase->id}/recheck")->assertForbidden();
        }
    });

    it('denies guests and signed-in customers', function () {
        $purchase = pu5Purchase(['timeout']);

        $this->get('/admin/purchases')->assertRedirect(route('admin.login'));
        $this->actingAs($purchase->user, 'web')->get('/admin/purchases')->assertRedirect(route('admin.login'));
        $this->get("/admin/purchases/{$purchase->id}")->assertRedirect(route('admin.login'));
        $this->post("/admin/purchases/{$purchase->id}/recheck")->assertRedirect(route('admin.login'));
        expect($purchase->fresh()->check_count)->toBe(0);
    });

    it('shows Purchases under Operations in the navigation', function () {
        $this->actingAs(pu5Staff(), 'admin')->get('/admin')->assertSee('data-nav="purchases"', false);
    });
});

describe('list, filters and search', function () {
    it('filters by status, service, network, provider and date', function () {
        $ok = pu5Purchase(['succeeded']);
        $failed = pu5Purchase(['failed_definite', 'failed_definite']);
        $pending = pu5Purchase(['timeout']);
        $airtimePlan = puxPlan('airtime', 0, true);
        puxRoute($airtimePlan);
        FakeProvider::$purchaseScript = ['succeeded'];
        $airtime = puxService()->purchase(puxCustomer(), $airtimePlan, '08012345678', 10_000, 'air');
        $this->actingAs(pu5Staff(), 'admin');

        $this->get('/admin/purchases?status=failed')->assertSee($failed->reference)->assertDontSee($ok->reference)->assertDontSee($pending->reference);
        $this->get('/admin/purchases?status=pending')->assertSee($pending->reference)->assertDontSee($failed->reference);
        $this->get('/admin/purchases?service='.$airtime->service_id)->assertSee($airtime->reference)->assertDontSee($ok->reference);
        $this->get('/admin/purchases?network=mtn')->assertSee($ok->reference);
        $this->get('/admin/purchases?network=glo')->assertDontSee($ok->reference)->assertSee('No purchases found');
        $okProvider = $ok->successfulAttempt->provider_id;
        $this->get('/admin/purchases?provider='.$okProvider)->assertSee($ok->reference)->assertDontSee($failed->reference);
        $this->get('/admin/purchases?status=refunded')->assertSessionHasErrors('status');

        $this->travel(3)->days();
        $late = pu5Purchase(['succeeded']);
        $today = now()->toDateString();
        $this->get("/admin/purchases?from={$today}&to={$today}")->assertSee($late->reference)->assertDontSee($ok->reference);
        $this->get('/admin/purchases?from='.now()->subDays(4)->toDateString().'&to='.now()->subDays(2)->toDateString())->assertSee($ok->reference)->assertDontSee($late->reference);
        $this->get('/admin/purchases?from=2026-10-10&to=2026-10-01')->assertSessionHasErrors('to');
    });

    it('searches by reference, recipient (any accepted phone format) and customer', function () {
        $ada = pu5Purchase(['succeeded'], ['name' => 'Ada Buyer', 'email' => 'ada.buyer@example.test'], '08011112222');
        $bola = pu5Purchase(['succeeded'], ['name' => 'Bola Other', 'email' => 'bola@example.test'], '08033334444');
        $this->actingAs(pu5Staff(), 'admin');

        $this->get('/admin/purchases?q='.$ada->reference)->assertSee($ada->reference)->assertDontSee($bola->reference);
        $this->get('/admin/purchases?q=08011112222')->assertSee($ada->reference)->assertDontSee($bola->reference);
        $this->get('/admin/purchases?q='.urlencode('+2348033334444'))->assertSee($bola->reference)->assertDontSee($ada->reference);
        $this->get('/admin/purchases?q=0803333')->assertSee($bola->reference)->assertDontSee($ada->reference);
        $this->get('/admin/purchases?q=Ada+Buy')->assertSee($ada->reference)->assertDontSee($bola->reference);
        $this->get('/admin/purchases?q=bola@')->assertSee($bola->reference)->assertDontSee($ada->reference);
        $this->get('/admin/purchases?q='.urlencode('%'))->assertDontSee($ada->reference)->assertDontSee($bola->reference);
    });

    it('paginates 25 per page', function () {
        $plan = puxPlan();
        puxRoute($plan);
        foreach (range(1, 27) as $i) {
            pu5Purchase(['succeeded'], [], '08012345678', $plan);
        }
        $this->actingAs(pu5Staff(), 'admin');

        $first = $this->get('/admin/purchases')->assertOk()->getContent();
        $second = $this->get('/admin/purchases?page=2')->assertOk()->getContent();

        expect(substr_count($first, 'data-purchase="'))->toBe(25)->and(substr_count($second, 'data-purchase="'))->toBe(2);
    });

    it('does not run more queries as the page fills (no N+1)', function () {
        $plan = puxPlan();
        puxRoute($plan);
        $staff = pu5Staff();
        $count = function () use ($staff) {
            $this->actingAs($staff, 'admin');
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/admin/purchases')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        foreach (range(1, 3) as $i) {
            pu5Purchase(['succeeded'], [], '08012345678', $plan);
        }
        $count(); // warm-up (permission cache and similar one-off queries)
        $few = $count();
        foreach (range(1, 12) as $i) {
            pu5Purchase(['succeeded'], [], '08012345678', $plan);
        }

        expect($count())->toBe($few);
    });
});

describe('detail', function () {
    it('shows snapshots, money, transactions, attempts and history, separating internal data', function () {
        $purchase = pu5Purchase(['failed_definite', 'succeeded']);
        $html = $this->actingAs(pu5Staff(), 'admin')->get("/admin/purchases/{$purchase->id}")->assertOk()->getContent();

        $attempts = $purchase->attempts;
        expect($html)->toContain('Data · MTN · 1GB')->toContain('08012345678')->toContain('₦500.00')
            ->toContain($purchase->debitTransaction->reference)
            ->toContain('data-internal')->toContain('₦460.00')->toContain('₦40.00') // cost and margin of route 2
            ->toContain('data-attempt="1"')->toContain('data-attempt="2"')
            ->toContain('data-attempt-status="failed_definite"')->toContain('data-attempt-status="succeeded"')
            ->toContain($attempts[0]->request_reference)->toContain((string) $attempts[1]->provider_reference)
            ->toContain('data-status-change="successful"')
            ->toContain('data-final-note')
            ->not->toContain('data-recheck');
    });

    it('shows the refund of a failed purchase and escapes stored text', function () {
        $purchase = pu5Purchase(['failed_definite', 'failed_definite']);
        DB::table('purchase_attempts')->where('purchase_id', $purchase->id)->update(['error_message' => '<script>alert(1)</script>']);

        $html = $this->actingAs(pu5Staff(), 'admin')->get("/admin/purchases/{$purchase->id}")->assertOk()->getContent();

        expect($html)->toContain($purchase->refundTransaction->reference)->toContain('Every provider declined the purchase.')
            ->not->toContain('<script>alert(1)</script>')->toContain('&lt;script&gt;');
    });

    it('never shows provider credentials, tokens or secrets', function () {
        $purchase = pu5Purchase(['failed_definite', 'succeeded']);
        $html = $this->actingAs(pu5Staff(), 'admin')->get("/admin/purchases/{$purchase->id}")->getContent()
            .$this->get('/admin/purchases')->getContent();

        foreach ([PUX_KEY, 'api_key', 'secret', 'Bearer', 'Authorization', 'password'] as $needle) {
            expect(str_contains($html, $needle))->toBeFalse("found {$needle}");
        }
    });

    it('returns 404 for unknown purchases', function () {
        $this->actingAs(pu5Staff(), 'admin')->get('/admin/purchases/999999')->assertNotFound();
    });
});

describe('staff re-check', function () {
    it('re-checks a pending purchase, records the staff member and shows the result', function () {
        $purchase = pu5Purchase(['timeout']);
        $staff = pu5Staff(['purchases.view', 'purchases.manage']);
        FakeProvider::$queryScript = ['succeeded'];

        $this->actingAs($staff, 'admin')->get("/admin/purchases/{$purchase->id}")->assertSee('data-recheck', false);
        $this->from("/admin/purchases/{$purchase->id}")->post("/admin/purchases/{$purchase->id}/recheck")
            ->assertRedirect("/admin/purchases/{$purchase->id}")->assertSessionHas('status', 'Re-checked: the purchase is now Successful.');

        $change = PurchaseStatusChange::where('purchase_id', $purchase->id)->latest('id')->first();
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Successful)
            ->and($change->source)->toBe(PurchaseSource::Admin)->and($change->changed_by)->toBe($staff->id);
    });

    it('records a staff re-check without a definite outcome and changes nothing else', function () {
        $purchase = pu5Purchase(['timeout']);
        $staff = pu5Staff();
        FakeProvider::$queryScript = ['unknown'];

        $this->actingAs($staff, 'admin')->post("/admin/purchases/{$purchase->id}/recheck")
            ->assertSessionHas('status', 'Re-checked: no definite provider outcome yet (Pending).');

        $change = PurchaseStatusChange::where('purchase_id', $purchase->id)->latest('id')->first();
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Pending)->and($purchase->fresh()->refund_transaction_id)->toBeNull()
            ->and($change->changed_by)->toBe($staff->id)->and($change->new_status)->toBe(PurchaseStatus::Pending)
            ->and($change->note)->toContain('no definite provider outcome');
    });

    it('lets view-only staff look but not re-check', function () {
        $purchase = pu5Purchase(['timeout']);
        $this->actingAs(pu5Staff(['purchases.view']), 'admin');

        $this->get("/admin/purchases/{$purchase->id}")->assertOk()->assertDontSee('data-recheck', false);
        $this->post("/admin/purchases/{$purchase->id}/recheck")->assertForbidden();
        expect(FakeProvider::$calls)->toHaveCount(1); // only the original purchase call
    });

    it('refuses inactive staff even with the permission', function () {
        $purchase = pu5Purchase(['timeout']);
        $staff = pu5Staff();
        $staff->forceFill(['status' => 'disabled'])->save();

        $this->actingAs($staff, 'admin')->post("/admin/purchases/{$purchase->id}/recheck")->assertRedirect();
        expect($purchase->fresh()->check_count)->toBe(0);
    });

    it('offers no action on a final purchase and refuses a re-check of one', function () {
        $purchase = pu5Purchase(['succeeded']);
        $this->actingAs(pu5Staff(), 'admin');

        $this->get("/admin/purchases/{$purchase->id}")->assertDontSee('data-recheck', false)->assertSee('can no longer change');
        $this->post("/admin/purchases/{$purchase->id}/recheck")->assertSessionHasErrors('recheck');
        expect(PurchaseStatusChange::where('purchase_id', $purchase->id)->count())->toBe(2);
    });

    it('records nothing when the re-check action reaches a purchase another process already settled', function () {
        $purchase = pu5Purchase(['succeeded']);

        app(RecheckPurchase::class)->handle($purchase, pu5Staff());

        expect(PurchaseStatusChange::where('purchase_id', $purchase->id)->count())->toBe(2);
    });

    it('has no mark-successful, force-fail, refund, edit or delete routes', function () {
        $routes = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'admin/purchases'))
            ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())->sort()->values()->all();

        // Phase 11 CP3 adds only the POST exact-match NIN/BVN search and its clear action.
        expect($routes)->toBe(['GET|HEAD admin/purchases', 'GET|HEAD admin/purchases/{purchase}', 'POST admin/purchases/identity-search',
            'POST admin/purchases/identity-search/clear', 'POST admin/purchases/{purchase}/recheck']);
    });
});

it('adds only the approved customer purchase routes and no production provider', function () {
    $config = require base_path('config/providers.php');

    expect(collect(Route::getRoutes())->map->uri()->filter(fn ($u) => preg_match('/(buy|purchase)/i', $u) && ! str_starts_with($u, 'admin/'))->unique()->sort()->values()->all())
        ->toBe(['buy', 'buy/bvn', 'buy/bvn/confirm', 'buy/exam-pin', 'buy/exam-pin/confirm', 'buy/nin', 'buy/nin/confirm', 'buy/{service}', 'buy/{service}/confirm',
            'purchases', 'purchases/{reference}'])
        ->and($config['drivers'])->toBe([]);
});
