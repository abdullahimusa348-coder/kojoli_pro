<?php

use App\Models\Purchase;
use App\Models\SystemUser;
use App\Services\Admin\DashboardMetrics;
use App\Services\Settings\SettingsStore;
use App\Support\BusinessTime;
use App\Support\Enums\SystemRole;
use App\Support\Purchases\PurchaseSource;
use App\Support\Purchases\PurchaseStatus;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 10 Step 3, CP1: Today's Sales on the admin dashboard and the matching
 * "Completed: Today" filter on the admin Purchases list. A sale is a purchase
 * that became successful during the current business day in the "Business
 * timezone" setting (default Africa/Lagos, UTC+1, so the Lagos day runs from
 * 23:00 UTC to 23:00 UTC). Purchases run through PurchaseService with the
 * test-only FakeProvider; no real provider or HTTP call.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    puxDrivers();
    Http::preventStrayRequests();
});

function tsAt(string $utc): void
{
    Carbon::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
}

/** Buys at the given UTC time; the provider answers with $script. */
function tsBuy(string $utc, array $script, int $price = 50_000): Purchase
{
    tsAt($utc);
    $plan = puxPlan('data', $price);
    puxRoute($plan);
    FakeProvider::reset();
    FakeProvider::$purchaseScript = $script;

    return puxService()->purchase(puxCustomer(1_000_000), $plan, '08012345678', null, (string) Str::uuid());
}

/** Re-checks an unclear purchase at the given UTC time; the status check answers $answer. */
function tsSettle(Purchase $purchase, string $utc, string $answer): Purchase
{
    tsAt($utc);
    FakeProvider::reset();
    FakeProvider::$queryScript = [$answer];

    return puxService()->recheck($purchase->fresh(), PurchaseSource::Reconcile);
}

function tsStaff(?array $permissions = null): SystemUser
{
    $staff = SystemUser::factory()->create();
    if ($permissions === null) {
        $staff->assignRole(SystemRole::SuperAdmin->value);
    } else {
        $staff->assignRole(Role::create(['name' => 'TS '.implode(' ', $permissions), 'guard_name' => 'admin'])
            ->givePermissionTo(['admin.access', ...$permissions]));
    }

    return $staff;
}

/** The HTML of one dashboard card. */
function tsCard(string $html, string $key = 'todays-sales'): string
{
    preg_match('/<div[^>]*data-card="'.$key.'".*?<\/div>/s', $html, $match);

    return $match[0] ?? '';
}

/** @return list<string> queries that read the purchases table */
function tsPurchaseQueries(): array
{
    return collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => preg_match('/from\s+["`]?purchases["`]?/i', $q))->values()->all();
}

describe('Today’s Sales', function () {
    it('counts and totals only purchases that became successful today', function () {
        tsBuy('2026-10-04 08:00:00', ['succeeded'], 50_000);
        tsBuy('2026-10-04 09:00:00', ['succeeded'], 120_000);
        tsBuy('2026-10-04 09:10:00', ['unknown'], 70_000);           // pending
        tsBuy('2026-10-04 09:20:00', ['failed_definite'], 80_000);   // failed and refunded
        $review = tsBuy('2026-10-03 06:00:00', ['unknown'], 90_000);
        tsSettle($review, '2026-10-04 07:00:00', 'unknown');         // no answer after 24 hours: review
        tsAt('2026-10-04 10:00:00');

        expect($review->fresh()->status)->toBe(PurchaseStatus::Review)
            ->and(app(DashboardMetrics::class)->salesToday())->toBe(['count' => 2, 'total_kobo' => 170_000]);

        $this->actingAs(tsStaff(), 'admin');
        expect(tsCard($this->get('/admin')->assertOk()->getContent()))->toContain('₦1,700.00')
            ->toContain('2 successful purchases today (Africa/Lagos)')
            ->not->toContain('Not live')
            ->not->toContain('0801');
    });

    it('counts a sale on the business day it became successful, not the day it was created', function () {
        $late = tsBuy('2026-10-03 22:30:00', ['unknown'], 60_000);  // 23:30 in Lagos on 3 Oct: unclear
        tsSettle($late, '2026-10-03 23:30:00', 'succeeded');         // 00:30 in Lagos on 4 Oct: delivered
        tsBuy('2026-10-03 22:00:00', ['succeeded'], 40_000);         // 23:00 in Lagos on 3 Oct: yesterday's sale
        tsAt('2026-10-04 12:00:00');

        expect(app(DashboardMetrics::class)->salesToday())->toBe(['count' => 1, 'total_kobo' => 60_000]);
    });

    it('uses midnight in Lagos, not midnight UTC, as the day boundary', function (string $completed, string $now, bool $counted) {
        tsBuy($completed, ['succeeded']);
        tsAt($now);

        expect(app(DashboardMetrics::class)->salesToday()['count'])->toBe($counted ? 1 : 0);
    })->with([
        'one second before midnight in Lagos' => ['2026-10-03 22:59:59', '2026-10-04 12:00:00', false],
        'at midnight in Lagos' => ['2026-10-03 23:00:00', '2026-10-04 12:00:00', true],
        'before midnight UTC, already the next day in Lagos' => ['2026-10-03 23:30:00', '2026-10-04 12:00:00', true],
        'the last second of the Lagos day' => ['2026-10-04 22:59:59', '2026-10-04 22:59:59', true],
        'exactly the next midnight in Lagos belongs to the next day' => ['2026-10-04 23:00:00', '2026-10-04 22:59:59', false],
        'once the next Lagos day has started' => ['2026-10-04 22:30:00', '2026-10-04 23:00:00', false],
    ]);

    it('follows the Business timezone setting', function () {
        tsBuy('2026-10-03 23:30:00', ['succeeded']);                 // 4 Oct in Lagos, still 3 Oct in UTC
        tsAt('2026-10-04 12:00:00');
        expect(BusinessTime::timezone())->toBe('Africa/Lagos')
            ->and(app(DashboardMetrics::class)->salesToday()['count'])->toBe(1);

        app(SettingsStore::class)->set('app.timezone', 'UTC');

        expect(BusinessTime::timezone())->toBe('UTC')
            ->and(app(DashboardMetrics::class)->salesToday()['count'])->toBe(0);
    });

    it('falls back to Africa/Lagos when the stored timezone is not valid', function () {
        app(SettingsStore::class)->set('app.timezone', 'Mars/Olympus_Mons');

        expect(BusinessTime::timezone())->toBe('Africa/Lagos');
    });

    it('shows zero sales as a live figure and keeps Today’s Revenue not live', function () {
        $this->actingAs(tsStaff(), 'admin');
        $html = $this->get('/admin')->assertOk()->getContent();

        expect(tsCard($html))->toContain('₦0.00')->toContain('0 successful purchases today (Africa/Lagos)')->not->toContain('Not live')
            ->and(tsCard($html, 'todays-revenue'))->toContain('Not live');
    });

    it('is shown with purchases.view, read with one query, and never computed for staff who cannot see it', function () {
        tsBuy('2026-10-04 08:00:00', ['succeeded']);
        tsAt('2026-10-04 10:00:00');

        $this->actingAs(tsStaff(['purchases.view']), 'admin');
        DB::enableQueryLog();
        $this->get('/admin')->assertOk()->assertSee('data-card="todays-sales"', false)->assertDontSee('data-card="wallet-balance"', false);
        // The card is one aggregate query (the dashboard's purchase-attention line adds its own, tested in CP2).
        expect(array_values(array_filter(tsPurchaseQueries(), fn ($q) => str_contains($q, 'sales_total'))))->toHaveCount(1);

        DB::flushQueryLog();
        $this->actingAs(tsStaff(['transactions.view']), 'admin');
        $this->get('/admin')->assertOk()->assertDontSee('data-card="todays-sales"', false)->assertSee('data-panel="recent-transactions"', false);
        expect(tsPurchaseQueries())->toBe([]);
    });
});

describe('the matching Purchases filter', function () {
    it('lists exactly the purchases the card counts', function () {
        $today = [tsBuy('2026-10-04 08:00:00', ['succeeded']), tsBuy('2026-10-04 09:00:00', ['succeeded'])];
        $late = tsBuy('2026-10-03 22:30:00', ['unknown']);
        $today[] = tsSettle($late, '2026-10-03 23:30:00', 'succeeded');
        $yesterday = tsBuy('2026-10-03 21:00:00', ['succeeded']);
        $pending = tsBuy('2026-10-04 09:30:00', ['unknown']);
        $failed = tsBuy('2026-10-04 09:40:00', ['failed_definite']);
        tsAt('2026-10-04 10:00:00');
        $this->actingAs(tsStaff(), 'admin');

        $url = route('admin.purchases', ['status' => 'successful', 'completed' => 'today']);
        $this->get('/admin')->assertSee('href="'.e($url).'"', false)->assertSee('View today’s purchases');

        $list = $this->get($url)->assertOk()->assertSee('data-completed-today', false)->assertSee('Today (Africa/Lagos)');
        foreach ($today as $purchase) {
            $list->assertSee($purchase->reference);
        }
        foreach ([$yesterday, $pending, $failed] as $purchase) {
            $list->assertDontSee($purchase->reference);
        }
        expect(substr_count($list->getContent(), 'data-purchase="'))->toBe(app(DashboardMetrics::class)->salesToday()['count'])->toBe(3);
    });

    it('shows every purchase completed today when no status is chosen, and never pending ones', function () {
        $success = tsBuy('2026-10-04 08:00:00', ['succeeded']);
        $failed = tsBuy('2026-10-04 08:30:00', ['failed_definite']);
        $pending = tsBuy('2026-10-04 09:00:00', ['unknown']);
        tsAt('2026-10-04 10:00:00');
        $this->actingAs(tsStaff(), 'admin');

        $this->get('/admin/purchases?completed=today')->assertOk()
            ->assertSee($success->reference)->assertSee($failed->reference)->assertDontSee($pending->reference);
        $this->get('/admin/purchases')->assertOk()->assertSee($pending->reference)->assertDontSee('data-completed-today', false);
    });

    it('uses the same Business timezone as the card', function () {
        $late = tsBuy('2026-10-03 23:30:00', ['succeeded']);
        tsAt('2026-10-04 12:00:00');
        $this->actingAs(tsStaff(), 'admin');
        $this->get('/admin/purchases?completed=today')->assertSee($late->reference);

        app(SettingsStore::class)->set('app.timezone', 'UTC');

        $this->get('/admin/purchases?completed=today')->assertDontSee($late->reference)->assertSee('Today (UTC)');
    });

    it('accepts only the today value and stays behind purchases.view', function () {
        $this->actingAs(tsStaff(), 'admin');
        $this->get('/admin/purchases?completed=yesterday')->assertSessionHasErrors('completed');

        $this->actingAs(tsStaff(['transactions.view']), 'admin');
        $this->get('/admin/purchases?completed=today')->assertForbidden();
    });
});
