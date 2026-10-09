<?php

use App\Exceptions\Purchases\PurchaseException;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\PurchaseStatusChange;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Settings\SettingsStore;
use App\Services\Wallet\WalletService;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseSource;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Wallet\Direction;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 10 Step 1, CP3: purchase creation (durable idempotency, debit through
 * WalletService), execution with failover on definite failure only, and the
 * single compensating refund. Only the test-only FakeProvider is used.
 */

beforeEach(function () {
    puxDrivers();
    Http::preventStrayRequests();
});

function pu3Balance(Purchase|int $purchaseOrUser): int
{
    $userId = $purchaseOrUser instanceof Purchase ? $purchaseOrUser->user_id : $purchaseOrUser;

    return Wallet::where('user_id', $userId)->sole()->balance_kobo;
}

function pu3Buy(Plan $plan, $user, ?string $key = null, string $phone = '08012345678', ?int $face = null): Purchase
{
    return puxService()->purchase($user, $plan, $phone, $face, $key ?? (string) Str::uuid());
}

describe('creation and debit', function () {
    it('creates a pending purchase with snapshots and one debit through WalletService', function () {
        $plan = puxPlan();
        puxRoute($plan);
        $user = puxCustomer(200_000);

        $purchase = puxService()->create($user, $plan, '+234 801 234 5678', null, 'k-1');

        $debit = Transaction::findOrFail($purchase->debit_transaction_id);
        expect($purchase->status)->toBe(PurchaseStatus::Pending)
            ->and($purchase->reference)->toMatch('/^PUR-[0-9A-Z]{26}$/')
            ->and($purchase->recipient)->toBe('08012345678')
            ->and($purchase->amount_kobo)->toBe(50_000)
            ->and([$purchase->service_name, $purchase->product_name, $purchase->plan_name, $purchase->network->value])->toBe(['Data', 'MTN', '1GB', 'mtn'])
            ->and($debit->type)->toBe(TransactionType::Purchase)->and($debit->direction)->toBe(Direction::Debit)
            ->and($debit->idempotency_key)->toBe('purchase:'.$purchase->reference)
            ->and(WalletLedgerEntry::where('transaction_id', $debit->id)->sole()->entry_type)->toBe(LedgerEntryType::PurchaseDebit)
            ->and(pu3Balance($purchase))->toBe(150_000)
            ->and(PurchaseStatusChange::where('purchase_id', $purchase->id)->sole()->source)->toBe(PurchaseSource::Customer)
            ->and(FakeProvider::$calls)->toBe([]);
    });

    it('returns the existing purchase for a repeated key with the same details, without another debit or call', function () {
        $plan = puxPlan();
        puxRoute($plan);
        $user = puxCustomer();
        FakeProvider::$purchaseScript = ['succeeded'];

        $first = pu3Buy($plan, $user, 'same-key');
        $again = pu3Buy($plan, $user, 'same-key', '+2348012345678'); // same number, other format

        expect($again->id)->toBe($first->id)
            ->and(Purchase::count())->toBe(1)
            ->and(Transaction::where('type', 'purchase')->count())->toBe(1)
            ->and(FakeProvider::$calls)->toHaveCount(1)
            ->and(pu3Balance($first))->toBe(150_000);
    });

    it('refuses a repeated key with different details', function (array $other) {
        $plan = puxPlan();
        puxRoute($plan);
        $user = puxCustomer();
        pu3Buy($plan, $user, 'same-key');

        $otherPlan = $plan;
        if ($other['plan'] ?? false) {
            $otherPlan = puxPlan();
            puxRoute($otherPlan);
        }
        expect(fn () => puxService()->create($user, $otherPlan, $other['phone'] ?? '08012345678', null, 'same-key'))
            ->toThrow(PurchaseException::class, 'already used');
        expect(Purchase::count())->toBe(1)->and(pu3Balance(Purchase::first()))->toBe(150_000);
    })->with(['other phone' => [['phone' => '08099999999']], 'other plan' => [['plan' => true]]]);

    it('lets another customer use the same key', function () {
        $plan = puxPlan();
        puxRoute($plan);
        FakeProvider::$purchaseScript = ['succeeded', 'succeeded'];

        pu3Buy($plan, puxCustomer(), 'shared-key');
        pu3Buy($plan, puxCustomer(), 'shared-key');

        expect(Purchase::count())->toBe(2);
    });

    it('creates nothing and debits nothing when the request cannot be served', function (Closure $setup, string $message) {
        $plan = puxPlan();
        $user = puxCustomer(200_000);
        [$plan, $user, $phone] = $setup($plan, $user);
        $before = pu3Balance($user->id);

        expect(fn () => puxService()->create($user, $plan, $phone, null, 'k'))->toThrow(PurchaseException::class, $message);
        expect(Purchase::count())->toBe(0)
            ->and(Transaction::where('type', 'purchase')->count())->toBe(0)
            ->and(pu3Balance($user->id))->toBe($before)
            ->and(FakeProvider::$calls)->toBe([]);
    })->with([
        'no executable route' => [fn ($plan, $user) => [$plan, $user, '08012345678'], 'not available right now'],
        'route without an installed adapter' => [function ($plan, $user) {
            puxRoute($plan, 1, ['cost_type' => null], 'ratel');

            return [$plan, $user, '08012345678'];
        }, 'not available right now'],
        'insufficient balance' => [function ($plan, $user) {
            puxRoute($plan);

            return [$plan, puxCustomer(49_999), '08012345678'];
        }, 'not enough'],
        'frozen wallet' => [function ($plan, $user) {
            puxRoute($plan);
            app(WalletService::class)->setStatus(Wallet::where('user_id', $user->id)->sole(), WalletStatus::Frozen);

            return [$plan, $user, '08012345678'];
        }, 'frozen'],
        'invalid phone' => [function ($plan, $user) {
            puxRoute($plan);

            return [$plan, $user, '+2340801234567'];
        }, 'valid Nigerian phone'],
        'plan disabled' => [function ($plan, $user) {
            puxRoute($plan);
            $plan->forceFill(['is_active' => false])->save();

            return [$plan->fresh(), $user, '08012345678'];
        }, 'unavailable'],
        'no price for the customer type' => [function ($plan, $user) {
            puxRoute($plan);
            PlanPrice::where('plan_id', $plan->id)->update(['is_active' => false]);

            return [$plan->fresh(), $user, '08012345678'];
        }, 'Price disabled'],
    ]);

    it('respects the pricing.max_amount_kobo ceiling', function () {
        $plan = puxPlan('airtime', 0, true);
        puxRoute($plan, 1, ['cost_type' => 'percent', 'cost_discount_bps' => 300]);
        app(SettingsStore::class)->set('pricing.max_amount_kobo', 100_000);

        expect(fn () => puxService()->create(puxCustomer(5_000_000), $plan, '08012345678', 100_001, 'k'))->toThrow(PurchaseException::class);
        expect(Purchase::count())->toBe(0);
    });

    it('snapshots a variable price (face value, discount, fee) and charges the discounted amount', function () {
        $plan = puxPlan('airtime', 0, true);
        puxRoute($plan, 1, ['cost_type' => 'percent', 'cost_discount_bps' => 300]);
        FakeProvider::$purchaseScript = ['succeeded'];

        $purchase = pu3Buy($plan, puxCustomer(), null, '08012345678', 100_000);

        expect([$purchase->face_value_kobo, $purchase->discount_kobo, $purchase->fee_kobo, $purchase->amount_kobo])->toBe([100_000, 2_000, 0, 98_000])
            ->and($purchase->cost_kobo)->toBe(97_000)->and($purchase->margin_kobo)->toBe(1_000)
            ->and(FakeProvider::$calls[0]->faceValueKobo)->toBe(100_000)->and(FakeProvider::$calls[0]->amountKobo)->toBe(98_000);
    });
});

describe('execution', function () {
    it('succeeds on the first route with cost and margin from the route snapshot', function () {
        $plan = puxPlan();
        puxRoute($plan, 1);
        puxRoute($plan, 2);
        FakeProvider::$purchaseScript = ['succeeded'];

        $purchase = pu3Buy($plan, puxCustomer(200_000));

        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and($purchase->attempts)->toHaveCount(1)
            ->and($purchase->successfulAttempt->route_priority)->toBe(1)
            ->and($purchase->successfulAttempt->provider_reference)->toStartWith('FP-PRA-')
            ->and($purchase->cost_kobo)->toBe(45_000)->and($purchase->margin_kobo)->toBe(5_000)
            ->and($purchase->refund_transaction_id)->toBeNull()
            ->and(pu3Balance($purchase))->toBe(150_000)
            ->and(FakeProvider::$calls)->toHaveCount(1)
            ->and(FakeProvider::$calls[0]->recipient)->toBe('08012345678')
            ->and(FakeProvider::$calls[0]->providerPlanCode)->toBe('CODE1')
            ->and(PurchaseStatusChange::where('purchase_id', $purchase->id)->pluck('new_status')->map->value->all())->toBe(['pending', 'successful'])
            ->and(Artisan::call('wallet:verify'))->toBe(0);
    });

    it('fails over to the next route after a definite failure', function () {
        $plan = puxPlan();
        puxRoute($plan, 1);
        puxRoute($plan, 2, ['cost_type' => 'fixed', 'cost_kobo' => 46_000]);
        FakeProvider::$purchaseScript = ['failed_definite', 'succeeded'];

        $purchase = pu3Buy($plan, puxCustomer(200_000));

        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and($purchase->attempts->map(fn ($a) => [$a->route_priority, $a->status->value])->all())->toBe([[1, 'failed_definite'], [2, 'succeeded']])
            ->and($purchase->cost_kobo)->toBe(46_000)->and($purchase->margin_kobo)->toBe(4_000)
            ->and($purchase->refund_transaction_id)->toBeNull()
            ->and(pu3Balance($purchase))->toBe(150_000);
    });

    it('fails with exactly one refund when every route fails definitely', function () {
        $plan = puxPlan();
        puxRoute($plan, 1);
        puxRoute($plan, 2);
        puxRoute($plan, 3);
        FakeProvider::$purchaseScript = ['failed_definite', 'failed_definite', 'failed_definite'];

        $purchase = pu3Buy($plan, puxCustomer(200_000));
        puxService()->execute($purchase); // again: nothing more happens

        $refund = Transaction::findOrFail($purchase->refund_transaction_id);
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Failed)
            ->and($purchase->attempts)->toHaveCount(3)
            ->and($refund->direction)->toBe(Direction::Credit)->and($refund->idempotency_key)->toBe('purchase-refund:'.$purchase->reference)
            ->and(Transaction::where('type', 'purchase')->where('direction', 'credit')->count())->toBe(1)
            ->and(WalletLedgerEntry::where('entry_type', 'purchase_refund')->count())->toBe(1)
            ->and(pu3Balance($purchase))->toBe(200_000)
            ->and($purchase->cost_kobo)->toBeNull()
            ->and(FakeProvider::$calls)->toHaveCount(3)
            ->and(Artisan::call('wallet:verify'))->toBe(0);
    });

    it('stops at an unknown outcome: pending, no failover, no refund', function (string $script) {
        $plan = puxPlan();
        puxRoute($plan, 1);
        puxRoute($plan, 2);
        FakeProvider::$purchaseScript = [$script, 'succeeded'];

        $purchase = pu3Buy($plan, puxCustomer(200_000));
        puxService()->execute($purchase); // a second run must not fail over either

        expect($purchase->status)->toBe(PurchaseStatus::Pending)
            ->and($purchase->attempts)->toHaveCount(1)
            ->and($purchase->attempts->first()->status)->toBe(PurchaseAttemptStatus::Unknown)
            ->and($purchase->refund_transaction_id)->toBeNull()
            ->and($purchase->next_check_at)->not->toBeNull()
            ->and(FakeProvider::$calls)->toHaveCount(1)
            ->and(pu3Balance($purchase))->toBe(150_000);
    })->with(['unknown' => ['unknown'], 'timeout' => ['timeout'], 'adapter error' => ['exception']]);

    it('stops after a definite failure followed by an unknown outcome, without refund', function () {
        $plan = puxPlan();
        puxRoute($plan, 1);
        puxRoute($plan, 2);
        puxRoute($plan, 3);
        FakeProvider::$purchaseScript = ['failed_definite', 'timeout', 'succeeded'];

        $purchase = pu3Buy($plan, puxCustomer(200_000));

        expect($purchase->status)->toBe(PurchaseStatus::Pending)
            ->and($purchase->attempts->pluck('status')->map->value->all())->toBe(['failed_definite', 'unknown'])
            ->and(FakeProvider::$calls)->toHaveCount(2)
            ->and($purchase->refund_transaction_id)->toBeNull();
    });

    it('does not start another attempt while one is still in flight', function () {
        $plan = puxPlan();
        $route = puxRoute($plan, 1);
        puxRoute($plan, 2);
        $purchase = puxService()->create(puxCustomer(), $plan, '08012345678', null, 'k');
        (new PurchaseAttempt)->forceFill(['purchase_id' => $purchase->id, 'attempt_number' => 1, 'plan_provider_route_id' => $route->id,
            'provider_id' => $route->provider_id, 'route_priority' => 1, 'provider_plan_code' => 'CODE1', 'cost_type' => 'fixed', 'cost_kobo' => 45_000,
            'request_reference' => WalletService::reference('PRA'), 'status' => PurchaseAttemptStatus::Started, 'started_at' => now()])->save();

        expect(puxService()->execute($purchase)->status)->toBe(PurchaseStatus::Pending)->and(FakeProvider::$calls)->toBe([]);
    });

    it('refunds when no route is executable any more at execution time (nothing was sent)', function () {
        $plan = puxPlan();
        $route = puxRoute($plan, 1);
        $purchase = puxService()->create(puxCustomer(200_000), $plan, '08012345678', null, 'k');
        $route->forceFill(['is_active' => false])->save();

        $after = puxService()->execute($purchase);

        expect($after->status)->toBe(PurchaseStatus::Failed)->and($after->attempts)->toHaveCount(0)
            ->and($after->failure_reason)->toBe('No provider was available.')
            ->and(pu3Balance($after))->toBe(200_000)->and(FakeProvider::$calls)->toBe([]);
    });

    it('never touches a final purchase again', function () {
        $plan = puxPlan();
        puxRoute($plan);
        FakeProvider::$purchaseScript = ['succeeded'];
        $purchase = pu3Buy($plan, puxCustomer());

        puxService()->execute($purchase);
        puxService()->execute($purchase);

        expect(FakeProvider::$calls)->toHaveCount(1)->and(PurchaseAttempt::count())->toBe(1);
    });
});

describe('snapshots', function () {
    it('keeps the price and route cost snapshots when prices and routes change later', function () {
        $plan = puxPlan();
        $route = puxRoute($plan);
        FakeProvider::$purchaseScript = ['succeeded'];
        $purchase = pu3Buy($plan, puxCustomer(200_000));

        PlanPrice::where('plan_id', $plan->id)->update(['price_kobo' => 99_000]);
        $route->forceFill(['cost_kobo' => 1, 'provider_plan_code' => 'CHANGED'])->save();
        $plan->forceFill(['name' => 'Renamed'])->save();

        $purchase = $purchase->fresh();
        expect($purchase->amount_kobo)->toBe(50_000)->and($purchase->plan_name)->toBe('1GB')
            ->and($purchase->cost_kobo)->toBe(45_000)->and($purchase->margin_kobo)->toBe(5_000)
            ->and($purchase->successfulAttempt->cost_kobo)->toBe(45_000)
            ->and($purchase->successfulAttempt->provider_plan_code)->toBe('CODE1');
    });
});

it('ships no production provider and creates no purchases outside tests', function () {
    $config = require base_path('config/providers.php');

    expect($config['drivers'])->toBe([])
        ->and(file_exists(app_path('Services/Providers/FakeProvider.php')))->toBeFalse()
        ->and(Purchase::count())->toBe(0);
});
