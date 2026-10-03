<?php

use App\Actions\Admin\Purchases\RecheckPurchase;
use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\PurchaseStatusChange;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Providers\Data\ProviderQueryRequest;
use App\Services\Wallet\WalletService;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseSource;
use App\Support\Purchases\PurchaseStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 10 Step 1, CP4: re-checking purchases with an unclear provider
 * outcome (scheduled reconciliation and staff re-check, same logic).
 */

beforeEach(function () {
    puxDrivers();
    Http::preventStrayRequests();
});

/** A debited purchase whose first route answered unclearly; route 2 exists but must never be tried. */
function pu4Unclear(string $script = 'timeout'): Purchase
{
    $plan = puxPlan();
    puxRoute($plan, 1);
    puxRoute($plan, 2);
    FakeProvider::$purchaseScript = [$script];
    $purchase = puxService()->purchase(puxCustomer(200_000), $plan, '08012345678', null, (string) Str::uuid());
    FakeProvider::$calls = [];

    expect($purchase->status)->toBe(PurchaseStatus::Pending);

    return $purchase;
}

function pu4Queries(): int
{
    return count(array_filter(FakeProvider::$calls, fn ($c) => $c instanceof ProviderQueryRequest));
}

function pu4Purchases(): int
{
    return count(FakeProvider::$calls) - pu4Queries();
}

function pu4Staff(array $permissions = ['purchases.view', 'purchases.manage']): SystemUser
{
    (new RolesAndPermissionsSeeder)->run();
    $role = Role::create(['name' => 'PU4 '.Str::random(5), 'guard_name' => 'admin'])->givePermissionTo(['admin.access', ...$permissions]);
    $staff = SystemUser::factory()->create();
    $staff->assignRole($role->name);

    return $staff;
}

describe('provider re-check outcomes', function () {
    it('settles a re-checked success once, with cost and margin, without calling another route', function () {
        $purchase = pu4Unclear();
        $this->travel(3)->minutes();
        FakeProvider::$queryScript = ['succeeded'];

        Artisan::call('purchases:reconcile');
        Artisan::call('purchases:reconcile');

        $purchase->refresh();
        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and($purchase->successfulAttempt->route_priority)->toBe(1)
            ->and($purchase->cost_kobo)->toBe(45_000)->and($purchase->margin_kobo)->toBe(5_000)
            ->and($purchase->attempts)->toHaveCount(1)
            ->and(pu4Queries())->toBe(1)->and(pu4Purchases())->toBe(0)
            ->and($purchase->refund_transaction_id)->toBeNull()
            ->and(PurchaseStatusChange::where('purchase_id', $purchase->id)->latest('id')->first()->source)->toBe(PurchaseSource::Reconcile);
    });

    it('fails a re-checked definite failure with exactly one refund and no failover', function () {
        $purchase = pu4Unclear();
        $this->travel(3)->minutes();
        FakeProvider::$queryScript = ['failed_definite', 'failed_definite'];

        Artisan::call('purchases:reconcile');
        Artisan::call('purchases:reconcile');

        $purchase->refresh();
        expect($purchase->status)->toBe(PurchaseStatus::Failed)
            ->and($purchase->attempts)->toHaveCount(1)
            ->and($purchase->attempts->first()->status)->toBe(PurchaseAttemptStatus::FailedDefinite)
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(1)
            ->and(Wallet::find($purchase->wallet_id)->balance_kobo)->toBe(200_000)
            ->and(pu4Purchases())->toBe(0)
            ->and($purchase->failure_reason)->toBe('The provider confirmed the purchase failed.')
            ->and(Artisan::call('wallet:verify'))->toBe(0);
    });

    it('keeps an unknown re-check pending, never refunded, and schedules the next check', function () {
        $purchase = pu4Unclear();
        $this->travel(3)->minutes();
        FakeProvider::$queryScript = ['unknown'];

        Artisan::call('purchases:reconcile');

        $purchase->refresh();
        expect($purchase->status)->toBe(PurchaseStatus::Pending)
            ->and($purchase->check_count)->toBe(1)
            ->and($purchase->refund_transaction_id)->toBeNull()
            ->and($purchase->attempts->first()->status)->toBe(PurchaseAttemptStatus::Unknown)
            ->and($purchase->attempts->first()->last_checked_at)->not->toBeNull()
            ->and(Wallet::find($purchase->wallet_id)->balance_kobo)->toBe(150_000)
            ->and(pu4Purchases())->toBe(0);
    });

    it('never queries without a documented status lookup, and keeps the purchase pending', function () {
        FakeProvider::$queryable = false;
        $purchase = pu4Unclear();
        $this->travel(3)->minutes();

        Artisan::call('purchases:reconcile');

        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Pending)
            ->and($purchase->fresh()->check_count)->toBe(1)
            ->and(FakeProvider::$calls)->toBe([]);
    });

    it('never queries a provider whose adapter is no longer installed', function () {
        $purchase = pu4Unclear();
        config(['providers.drivers' => []]);
        $this->travel(3)->minutes();

        Artisan::call('purchases:reconcile');

        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Pending)->and(FakeProvider::$calls)->toBe([]);
    });

    it('records a provider reference learned from the re-check', function () {
        $purchase = pu4Unclear('timeout'); // no answer, so no provider reference yet
        $attempt = $purchase->attempts->first();
        expect($attempt->provider_reference)->toBeNull();
        $this->travel(3)->minutes();
        FakeProvider::$queryScript = ['succeeded'];

        Artisan::call('purchases:reconcile');

        expect($attempt->fresh()->provider_reference)->toBe('FPQ-'.$attempt->request_reference)->and($attempt->fresh()->status)->toBe(PurchaseAttemptStatus::Succeeded);
    });
});

describe('schedule and review', function () {
    it('follows the approved schedule: 2, 5, 15, 60 minutes, then every 6 hours', function () {
        $purchase = pu4Unclear();
        $base = $purchase->attempts->first()->finished_at;
        expect($purchase->next_check_at->diffInSeconds($base->copy()->addMinutes(2), true))->toBeLessThan(2);

        $expected = [5, 15, 60];
        foreach ([2, 5, 15] as $i => $minute) {
            $this->travelTo($base->copy()->addMinutes($minute)->addSecond());
            Artisan::call('purchases:reconcile');
            expect($purchase->fresh()->next_check_at->diffInSeconds($base->copy()->addMinutes($expected[$i]), true))->toBeLessThan(2);
        }

        $this->travelTo($base->copy()->addMinutes(60)->addSecond());
        Artisan::call('purchases:reconcile');
        expect($purchase->fresh()->next_check_at->diffInMinutes(now()->addHours(6), true))->toBeLessThan(1)
            ->and($purchase->fresh()->check_count)->toBe(4);
    });

    it('does not check before the purchase is due', function () {
        $purchase = pu4Unclear();
        $this->travel(1)->minutes();

        Artisan::call('purchases:reconcile');

        expect(FakeProvider::$calls)->toBe([])->and($purchase->fresh()->check_count)->toBe(0);
    });

    it('moves to review after 24 hours without a definite outcome, never refunding or failing over', function () {
        $purchase = pu4Unclear();
        $this->travel(24)->hours();
        $this->travel(1)->minutes();

        Artisan::call('purchases:reconcile');

        $purchase->refresh();
        expect($purchase->status)->toBe(PurchaseStatus::Review)
            ->and($purchase->refund_transaction_id)->toBeNull()
            ->and($purchase->attempts)->toHaveCount(1)
            ->and(pu4Purchases())->toBe(0)
            ->and(Wallet::find($purchase->wallet_id)->balance_kobo)->toBe(150_000)
            ->and($purchase->failure_reason)->toContain('24 hours')
            ->and(PurchaseStatusChange::where('purchase_id', $purchase->id)->where('new_status', 'review')->count())->toBe(1);
    });

    it('keeps re-checking a review purchase and settles it on a later definite outcome', function (string $answer, PurchaseStatus $final) {
        $purchase = pu4Unclear();
        $this->travel(25)->hours();
        Artisan::call('purchases:reconcile');
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Review);

        FakeProvider::$queryScript = [$answer];
        $this->travel(7)->hours();
        Artisan::call('purchases:reconcile');

        expect($purchase->fresh()->status)->toBe($final)
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe($final === PurchaseStatus::Failed ? 1 : 0);
    })->with([['succeeded', PurchaseStatus::Successful], ['failed_definite', PurchaseStatus::Failed]]);

    it('ignores final purchases', function () {
        $plan = puxPlan();
        puxRoute($plan);
        FakeProvider::$purchaseScript = ['succeeded'];
        $purchase = puxService()->purchase(puxCustomer(), $plan, '08012345678', null, 'k');
        FakeProvider::$calls = [];
        $this->travel(1)->days();

        Artisan::call('purchases:reconcile');

        expect(FakeProvider::$calls)->toBe([])->and($purchase->fresh()->status)->toBe(PurchaseStatus::Successful);
    });
});

describe('interrupted and unstarted purchases', function () {
    it('treats a stale started attempt as unknown and re-checks it, never retrying the purchase call', function () {
        $plan = puxPlan();
        $route = puxRoute($plan, 1);
        puxRoute($plan, 2);
        $purchase = puxService()->create(puxCustomer(), $plan, '08012345678', null, 'k');
        (new PurchaseAttempt)->forceFill(['purchase_id' => $purchase->id, 'attempt_number' => 1, 'plan_provider_route_id' => $route->id,
            'provider_id' => $route->provider_id, 'route_priority' => 1, 'provider_plan_code' => 'CODE1', 'cost_type' => 'fixed', 'cost_kobo' => 45_000,
            'request_reference' => WalletService::reference('PRA'), 'status' => PurchaseAttemptStatus::Started, 'started_at' => now()])->save();

        $this->travel(5)->minutes();
        Artisan::call('purchases:reconcile');
        expect(FakeProvider::$calls)->toBe([]); // still in flight

        $this->travel(6)->minutes();
        FakeProvider::$queryScript = ['succeeded'];
        Artisan::call('purchases:reconcile');

        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Successful)->and(pu4Purchases())->toBe(0)->and(pu4Queries())->toBe(1);
    });

    it('continues normal execution for a debited purchase that never started (e.g. interrupted request)', function () {
        $plan = puxPlan();
        puxRoute($plan);
        $purchase = puxService()->create(puxCustomer(), $plan, '08012345678', null, 'k');
        FakeProvider::$purchaseScript = ['succeeded'];
        $this->travel(3)->minutes();

        Artisan::call('purchases:reconcile');

        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Successful)->and(pu4Purchases())->toBe(1);
    });
});

describe('staff re-check', function () {
    it('uses the same logic, ignores the schedule and records the staff member', function () {
        $purchase = pu4Unclear();
        $staff = pu4Staff();
        FakeProvider::$queryScript = ['succeeded'];

        app(RecheckPurchase::class)->handle($purchase, $staff);

        $change = PurchaseStatusChange::where('purchase_id', $purchase->id)->latest('id')->first();
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Successful)
            ->and($change->source)->toBe(PurchaseSource::Admin)->and($change->changed_by)->toBe($staff->id);
    });

    it('requires purchases.manage, active staff, and keeps unknown outcomes unresolved', function () {
        $purchase = pu4Unclear();
        $viewer = pu4Staff(['purchases.view']);
        $inactive = pu4Staff();
        $inactive->forceFill(['status' => 'disabled'])->save();

        expect(fn () => app(RecheckPurchase::class)->handle($purchase, $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(RecheckPurchase::class)->handle($purchase, $inactive))->toThrow(AuthorizationException::class)
            ->and(FakeProvider::$calls)->toBe([]);

        FakeProvider::$queryScript = ['unknown'];
        expect(app(RecheckPurchase::class)->handle($purchase, pu4Staff())->status)->toBe(PurchaseStatus::Pending)
            ->and($purchase->fresh()->refund_transaction_id)->toBeNull();
    });

    it('offers no manual mark-successful or force-fail/refund action and no customer purchase routes', function () {
        expect(class_exists('App\\Actions\\Admin\\Purchases\\MarkPurchaseSuccessful'))->toBeFalse()
            ->and(class_exists('App\\Actions\\Admin\\Purchases\\ForceFailPurchase'))->toBeFalse()
            ->and(collect(Route::getRoutes())->map->uri()->filter(fn ($u) => str_contains($u, 'purchase') && ! str_starts_with($u, 'admin/purchases'))->values()->all())->toBe([]);
    });
});

it('is scheduled every five minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'purchases:reconcile'));

    expect($event->expression)->toBe('*/5 * * * *')->and($event->withoutOverlapping)->toBeTrue();
});
