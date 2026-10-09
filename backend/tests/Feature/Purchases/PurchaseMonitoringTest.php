<?php

use App\Models\Purchase;
use App\Models\SystemUser;
use App\Services\Purchases\PurchaseMonitor;
use App\Services\Purchases\PurchaseService;
use App\Support\Enums\SystemRole;
use App\Support\Purchases\PurchaseSource;
use App\Support\Purchases\PurchaseStatus;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 10 Step 3, CP2: staff monitoring of purchases. A check is overdue
 * when a pending or review purchase has been due for a status check (the
 * reconcile() due rule) for more than purchases.overdue_after_minutes (15).
 * "Today" is the CP1 business day (Africa/Lagos). Test-only FakeProvider.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    puxDrivers();
    Http::preventStrayRequests();
});

function pmAt(string $utc): void
{
    Carbon::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
}

/** Buys at the given UTC time; the provider answers with $script. */
function pmBuy(string $utc, array $script): Purchase
{
    pmAt($utc);
    $plan = puxPlan('data', 50_000);
    puxRoute($plan);
    FakeProvider::reset();
    FakeProvider::$purchaseScript = $script;

    return puxService()->purchase(puxCustomer(1_000_000), $plan, '08012345678', null, (string) Str::uuid());
}

/** A pending purchase created at the given UTC time and never executed (no check scheduled yet). */
function pmNeverStarted(string $utc): Purchase
{
    pmAt($utc);
    $plan = puxPlan('data', 50_000);
    puxRoute($plan);

    return puxService()->create(puxCustomer(1_000_000), $plan, '08012345678', null, (string) Str::uuid());
}

/** A pending purchase whose next status check is scheduled for the given UTC time. */
function pmScheduled(string $nextCheckUtc): Purchase
{
    $purchase = pmBuy('2026-10-04 09:00:00', ['unknown']);
    DB::table('purchases')->where('id', $purchase->id)->update(['next_check_at' => $nextCheckUtc]);

    return $purchase->fresh();
}

/** A purchase moved to review by reconciliation (no definite answer after 24 hours). */
function pmReview(): Purchase
{
    $purchase = pmBuy('2026-10-03 08:00:00', ['unknown']);
    pmAt('2026-10-04 09:00:00');
    FakeProvider::reset();
    FakeProvider::$queryScript = ['unknown'];

    return puxService()->recheck($purchase->fresh(), PurchaseSource::Reconcile);
}

function pmStaff(?array $permissions = null): SystemUser
{
    $staff = SystemUser::factory()->create();
    if ($permissions === null) {
        $staff->assignRole(SystemRole::SuperAdmin->value);
    } else {
        $staff->assignRole(Role::create(['name' => 'PM '.implode(' ', $permissions), 'guard_name' => 'admin'])
            ->givePermissionTo(['admin.access', ...$permissions]));
    }

    return $staff;
}

/** @return list<int> */
function pmOverdueIds(): array
{
    return Purchase::query()->checkOverdue()->orderBy('id')->pluck('id')->all();
}

describe('overdue detection', function () {
    it('flags a check once it has been due for more than 15 minutes', function (Closure $make, bool $overdue) {
        $purchase = $make();
        pmAt('2026-10-04 12:00:00');

        expect(in_array($purchase->id, pmOverdueIds(), true))->toBe($overdue);
    })->with([
        'scheduled check due exactly 15 minutes ago' => [fn () => pmScheduled('2026-10-04 11:45:00'), false],
        'scheduled check due 15 minutes and 1 second ago' => [fn () => pmScheduled('2026-10-04 11:44:59'), true],
        'scheduled check due an hour ago' => [fn () => pmScheduled('2026-10-04 11:00:00'), true],
        'scheduled check not due yet' => [fn () => pmScheduled('2026-10-04 12:10:00'), false],
        'never scheduled, created 17 minutes ago (due 15 minutes ago)' => [fn () => pmNeverStarted('2026-10-04 11:43:00'), false],
        'never scheduled, created 17 minutes and 1 second ago' => [fn () => pmNeverStarted('2026-10-04 11:42:59'), true],
        'never scheduled, created a minute ago' => [fn () => pmNeverStarted('2026-10-04 11:59:00'), false],
    ]);

    it('uses the same due time as reconciliation', function () {
        $scheduled = pmScheduled('2026-10-04 11:30:00');
        $never = pmNeverStarted('2026-10-04 11:00:00');
        $done = pmBuy('2026-10-04 08:00:00', ['succeeded']);

        expect($scheduled->checkDueAt()->toDateTimeString())->toBe('2026-10-04 11:30:00')
            ->and($never->checkDueAt()->toDateTimeString())->toBe('2026-10-04 11:02:00') // created + reconcile_min_age_minutes
            ->and($done->checkDueAt())->toBeNull();
    });

    it('flags a review purchase whose check is overdue', function () {
        $review = pmReview();
        expect($review->status)->toBe(PurchaseStatus::Review);

        pmAt($review->next_check_at->copy()->addMinutes(16)->toDateTimeString());
        expect(pmOverdueIds())->toBe([$review->id]);
    });

    it('never flags a successful or failed purchase, even with an old check time', function () {
        $unclear = pmBuy('2026-10-04 08:00:00', ['unknown']);
        pmAt('2026-10-04 08:05:00');
        FakeProvider::reset();
        FakeProvider::$queryScript = ['succeeded'];
        $settled = puxService()->recheck($unclear->fresh(), PurchaseSource::Reconcile);
        $failed = pmBuy('2026-10-04 08:10:00', ['failed_definite']);
        // Settling clears the check time; force an old one to prove the status alone keeps them out.
        DB::table('purchases')->whereIn('id', [$settled->id, $failed->id])->update(['next_check_at' => '2026-10-04 08:00:00']);
        pmAt('2026-10-04 12:00:00');

        expect($settled->status)->toBe(PurchaseStatus::Successful)->and($failed->status)->toBe(PurchaseStatus::Failed)
            ->and(pmOverdueIds())->toBe([]);
    });

    it('follows the configured threshold', function () {
        $purchase = pmScheduled('2026-10-04 11:40:00');
        pmAt('2026-10-04 12:00:00');
        expect(pmOverdueIds())->toBe([$purchase->id]);

        config(['purchases.overdue_after_minutes' => 30]);
        expect(pmOverdueIds())->toBe([]);
    });
});

describe('monitoring summary', function () {
    it('counts pending, review, overdue, the oldest pending purchase and today’s outcomes', function () {
        pmBuy('2026-10-03 21:00:00', ['succeeded']);                 // 22:00 in Lagos on 3 Oct: not today
        pmBuy('2026-10-04 08:00:00', ['succeeded']);
        pmBuy('2026-10-04 08:30:00', ['succeeded']);
        pmBuy('2026-10-04 09:00:00', ['failed_definite']);
        $review = pmReview();                                        // moved to review at 09:00 with its next check due then
        $old = pmNeverStarted('2026-10-04 11:00:00');                // due 11:02: overdue at 12:00
        $fresh = pmScheduled('2026-10-04 12:05:00');                 // created 09:00, not due yet
        pmAt('2026-10-04 12:00:00');

        expect(app(PurchaseMonitor::class)->summary())->toMatchArray([
            'pending' => 2, 'review' => 1, 'overdue' => 2, 'successful_today' => 2, 'failed_today' => 1,
        ])->and(app(PurchaseMonitor::class)->summary()['oldest_pending_at']->toDateTimeString())->toBe($fresh->created_at->toDateTimeString())
            ->and(app(PurchaseMonitor::class)->attention())->toBe(['review' => 1, 'overdue' => 2])
            ->and($review->status)->toBe(PurchaseStatus::Review)
            ->and($review->next_check_at->toDateTimeString())->toBe('2026-10-04 09:00:00')
            ->and(pmOverdueIds())->toBe([$review->id, $old->id]);
    });

    it('shows zero and no oldest pending age when there is nothing to watch', function () {
        expect(app(PurchaseMonitor::class)->summary())->toBe([
            'pending' => 0, 'review' => 0, 'overdue' => 0, 'oldest_pending_at' => null, 'successful_today' => 0, 'failed_today' => 0,
        ]);
    });
});

describe('Purchases page', function () {
    it('shows the monitoring summary with links to the matching lists', function () {
        pmBuy('2026-10-04 08:00:00', ['succeeded']);
        pmBuy('2026-10-04 08:30:00', ['failed_definite']);
        pmReview();
        pmNeverStarted('2026-10-04 11:00:00');
        pmAt('2026-10-04 12:00:00');
        $this->actingAs(pmStaff(), 'admin');

        $html = $this->get('/admin/purchases')->assertOk()->assertSee('data-purchase-monitor', false)->getContent();
        // Overdue: the never-started purchase (due 11:02) and the review purchase (check due 09:00).
        foreach (['pending' => '1', 'review' => '1', 'overdue' => '2', 'successful-today' => '1', 'failed-today' => '1'] as $tile => $value) {
            expect($html)->toMatch('/data-monitor="'.$tile.'"[^>]*>\s*<span[^>]*>[^<]*<\/span>\s*<span[^>]*>'.$value.'<\/span>/');
        }
        expect($html)->toContain('href="'.e(route('admin.purchases', ['overdue' => 1])).'"')
            ->toContain('href="'.e(route('admin.purchases', ['status' => 'review'])).'"')
            ->toContain('href="'.e(route('admin.purchases', ['status' => 'failed', 'completed' => 'today'])).'"')
            ->toContain('data-review-count')
            ->toContain('1 hour') // the oldest pending purchase, created at 11:00
            ->toContain('“Today” is the business day in Africa/Lagos');
    });

    it('lists exactly the overdue purchases and marks them on every list', function () {
        $overdue = pmNeverStarted('2026-10-04 11:00:00');
        $scheduled = pmScheduled('2026-10-04 11:30:00');
        $notYet = pmScheduled('2026-10-04 12:30:00');
        $done = pmBuy('2026-10-04 09:30:00', ['succeeded']);
        pmAt('2026-10-04 12:00:00');
        $this->actingAs(pmStaff(), 'admin');

        $list = $this->get('/admin/purchases?overdue=1')->assertOk()->assertSee('data-overdue-filter', false);
        $list->assertSee($overdue->reference)->assertSee($scheduled->reference)->assertDontSee($notYet->reference)->assertDontSee($done->reference);
        expect(substr_count($list->getContent(), 'data-purchase="'))->toBe(app(PurchaseMonitor::class)->overdueCount())->toBe(2);

        $all = $this->get('/admin/purchases')->assertOk()->getContent();
        expect(substr_count($all, 'data-overdue>'))->toBe(2)
            ->and($all)->toMatch('/data-purchase="'.$notYet->reference.'".*?Next check 4 Oct 2026, 12:30/s')
            ->and($all)->toMatch('/data-purchase="'.$overdue->reference.'".*?Next check 4 Oct 2026, 11:02/s');
        $this->get('/admin/purchases?overdue=2')->assertSessionHasErrors('overdue');
    });

    it('shows when a purchase went into review and no check line for final purchases', function () {
        $review = pmReview();
        $done = pmBuy('2026-10-04 09:30:00', ['succeeded']);
        pmAt('2026-10-04 12:00:00');
        $this->actingAs(pmStaff(), 'admin');

        $html = $this->get('/admin/purchases')->assertOk()->getContent();
        expect($html)->toMatch('/data-purchase="'.$review->reference.'".*?In review since 4 Oct 2026, 09:00/s');
        preg_match('/data-purchase="'.$done->reference.'".*?<\/li>/s', $html, $row);
        expect($row[0])->not->toContain('data-next-check');
    });

    it('keeps the monitoring behind purchases.view', function () {
        $this->actingAs(pmStaff(['transactions.view']), 'admin');
        $this->get('/admin/purchases?overdue=1')->assertForbidden();
    });
});

describe('dashboard', function () {
    it('shows what needs attention, with links, to staff with purchases.view', function () {
        pmReview();
        pmNeverStarted('2026-10-04 11:00:00');
        pmNeverStarted('2026-10-04 11:10:00');
        pmAt('2026-10-04 12:00:00');
        $this->actingAs(pmStaff(['purchases.view']), 'admin');

        $this->get('/admin')->assertOk()->assertSee('data-panel="purchase-attention"', false)
            ->assertSee('1 in review')->assertSee('3 overdue status checks') // the two never-started ones and the review check due at 09:00
            ->assertSee('href="'.e(route('admin.purchases', ['status' => 'review'])).'"', false)
            ->assertSee('href="'.e(route('admin.purchases', ['overdue' => 1])).'"', false);
    });

    it('shows nothing when no purchase needs attention', function () {
        pmBuy('2026-10-04 08:00:00', ['succeeded']);
        pmScheduled('2026-10-04 12:30:00');
        pmAt('2026-10-04 12:00:00');
        $this->actingAs(pmStaff(), 'admin');

        $this->get('/admin')->assertOk()->assertDontSee('data-panel="purchase-attention"', false);
    });

    it('never shows or computes it for staff without purchases.view', function () {
        pmReview();
        pmAt('2026-10-04 12:00:00');
        $this->actingAs(pmStaff(['transactions.view']), 'admin');
        DB::enableQueryLog();

        $this->get('/admin')->assertOk()->assertDontSee('data-panel="purchase-attention"', false);
        expect(collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => preg_match('/from\s+["`]?purchases["`]?/i', $q))->all())->toBe([]);
    });

    it('reads purchases with a fixed number of queries', function () {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/admin')->assertOk();

            return collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => preg_match('/from\s+["`]?purchases["`]?/i', $q))->count();
        };
        pmReview();
        $this->actingAs(pmStaff(), 'admin');
        $few = $count();
        foreach (range(1, 5) as $i) {
            pmNeverStarted('2026-10-04 08:0'.$i.':00');
        }
        pmAt('2026-10-04 12:00:00');

        expect($few)->toBe(3)->and($count())->toBe(3); // Today's Sales, review count, overdue count
    });

    it('keeps the approved three cards per row', function () {
        $this->actingAs(pmStaff(), 'admin');

        $this->get('/admin')->assertOk()->assertSee('sm:grid-cols-2 xl:grid-cols-3', false)->assertDontSee('xl:grid-cols-5', false);
    });
});

describe('reconciliation log line', function () {
    it('logs the counts of a run that checked something', function () {
        $unclear = pmBuy('2026-10-04 08:00:00', ['unknown']);
        pmAt('2026-10-04 08:10:00');
        FakeProvider::reset();
        FakeProvider::$queryScript = ['succeeded'];
        Log::spy();

        Artisan::call('purchases:reconcile');

        expect($unclear->fresh()->status)->toBe(PurchaseStatus::Successful);
        Log::shouldHaveReceived('log')->once()->with('info', 'Purchase re-checks ran', ['checked' => 1, 'settled' => 1, 'review' => 0, 'errors' => 0]);
    });

    it('logs a warning when a re-check failed', function () {
        $this->mock(PurchaseService::class, fn ($mock) => $mock->shouldReceive('reconcile')->once()
            ->andReturn(['checked' => 3, 'settled' => 1, 'review' => 0, 'errors' => 1]));
        Log::spy();

        Artisan::call('purchases:reconcile');

        Log::shouldHaveReceived('log')->once()->with('warning', 'Purchase re-checks ran', ['checked' => 3, 'settled' => 1, 'review' => 0, 'errors' => 1]);
    });

    it('logs nothing when nothing was due', function () {
        pmBuy('2026-10-04 08:00:00', ['succeeded']);
        Log::spy();

        Artisan::call('purchases:reconcile');

        Log::shouldNotHaveReceived('log');
    });
});
