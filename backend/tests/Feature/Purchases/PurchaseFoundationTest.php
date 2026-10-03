<?php

use App\Exceptions\Purchases\PurchaseException;
use App\Models\Plan;
use App\Models\PlanProviderRoute;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\PurchaseStatusChange;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Services\Wallet\WalletService;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Permissions\PermissionModule;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseSource;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Phase 10 Step 1, CP1: purchase tables, statuses, wallet types, model guards
 * and permissions. No purchase engine, provider execution or UI exists yet.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function puPlan(): Plan
{
    $service = Service::factory()->create(['name' => 'Data', 'slug' => 'data-'.Str::random(5)]);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'MTN', 'code' => 'data-mtn-'.Str::random(5), 'network' => 'mtn']);

    return Plan::factory()->create(['product_id' => $product->id, 'name' => '1GB', 'code' => $product->code.'-1gb']);
}

/** A pending purchase built directly (the engine that creates them arrives in CP3). */
function puPurchase(array $attributes = [], ?Plan $plan = null): Purchase
{
    $plan ??= puPlan();
    $user = $attributes['user'] ?? User::factory()->create();
    unset($attributes['user']);
    $wallet = app(WalletService::class)->walletFor($user);

    return tap((new Purchase)->forceFill($attributes + [
        'reference' => WalletService::reference('PUR'),
        'user_id' => $user->id,
        'wallet_id' => $wallet->id,
        'plan_id' => $plan->id,
        'service_id' => $plan->product->service_id,
        'service_name' => 'Data',
        'product_name' => 'MTN',
        'plan_name' => '1GB',
        'network' => 'mtn',
        'user_type' => $user->user_type,
        'amount_type' => 'fixed',
        'recipient' => '08012345678',
        'amount_kobo' => 50_000,
        'status' => PurchaseStatus::Pending,
        'idempotency_key' => (string) Str::uuid(),
        'request_fingerprint' => Purchase::fingerprint($plan->id, '08012345678', null),
    ]))->save();
}

function puRoute(Plan $plan, int $priority = 1): PlanProviderRoute
{
    $provider = Provider::factory()->create(['name' => 'Provider '.Str::random(4), 'code' => 'prov-'.Str::lower(Str::random(6))]);

    return tap((new PlanProviderRoute)->forceFill(['plan_id' => $plan->id, 'provider_id' => $provider->id, 'priority' => $priority,
        'provider_plan_code' => 'CODE'.$priority, 'is_active' => true, 'cost_type' => 'fixed', 'cost_kobo' => 45_000]))->save();
}

function puAttempt(Purchase $purchase, PlanProviderRoute $route, int $number = 1, array $attributes = []): PurchaseAttempt
{
    return tap((new PurchaseAttempt)->forceFill($attributes + [
        'purchase_id' => $purchase->id,
        'attempt_number' => $number,
        'plan_provider_route_id' => $route->id,
        'provider_id' => $route->provider_id,
        'route_priority' => $route->priority,
        'provider_plan_code' => $route->provider_plan_code,
        'cost_type' => $route->cost_type,
        'cost_kobo' => $route->cost_kobo,
        'cost_discount_bps' => $route->cost_discount_bps,
        'request_reference' => WalletService::reference('PRA'),
        'status' => PurchaseAttemptStatus::Started,
        'started_at' => now(),
    ]))->save();
}

/** Records the purchase debit (CP1 hardening: success and failure both require it). */
function puDebited(Purchase $purchase): Purchase
{
    $purchase->forceFill(['debit_transaction_id' => puTransaction($purchase)])->save();

    return $purchase->fresh();
}

/** A wallet transaction to link as debit or refund. */
function puTransaction(Purchase $purchase, string $direction = 'debit'): int
{
    $wallets = app(WalletService::class);
    $wallet = $purchase->wallet;
    if ($direction === 'debit') {
        $wallets->credit($wallet, 100_000, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Test funding');

        return $wallets->debit($wallet, $purchase->amount_kobo, LedgerEntryType::PurchaseDebit, TransactionType::Purchase, 'Purchase', 'purchase:'.$purchase->reference)->transaction->id;
    }

    return $wallets->credit($wallet, $purchase->amount_kobo, LedgerEntryType::PurchaseRefund, TransactionType::Purchase, 'Purchase refund', 'purchase-refund:'.$purchase->reference)->transaction->id;
}

describe('schema', function () {
    it('creates the three purchase tables with integer kobo columns', function () {
        expect(Schema::hasColumns('purchases', ['reference', 'user_id', 'wallet_id', 'plan_id', 'service_id', 'service_name', 'product_name',
            'plan_name', 'network', 'user_type', 'amount_type', 'recipient', 'face_value_kobo', 'discount_kobo', 'fee_kobo', 'amount_kobo',
            'currency', 'status', 'idempotency_key', 'request_fingerprint', 'debit_transaction_id', 'refund_transaction_id',
            'successful_attempt_id', 'cost_kobo', 'margin_kobo', 'failure_reason', 'check_count', 'next_check_at', 'completed_at']))->toBeTrue()
            ->and(Schema::hasColumns('purchase_attempts', ['purchase_id', 'attempt_number', 'plan_provider_route_id', 'provider_id', 'route_priority',
                'provider_plan_code', 'cost_type', 'cost_kobo', 'cost_discount_bps', 'request_reference', 'provider_reference', 'status',
                'error_code', 'error_message', 'started_at', 'finished_at', 'duration_ms', 'last_checked_at']))->toBeTrue()
            ->and(Schema::hasColumns('purchase_status_changes', ['purchase_id', 'old_status', 'new_status', 'source', 'changed_by', 'note', 'created_at']))->toBeTrue()
            ->and(Schema::hasColumn('purchase_status_changes', 'updated_at'))->toBeFalse();
    });

    it('keeps money out of catalog, pricing and provider tables and adds no balance to purchases', function () {
        foreach (['plans', 'products', 'services', 'providers', 'plan_provider_routes', 'plan_prices'] as $table) {
            expect(Schema::hasColumn($table, 'purchase_id'))->toBeFalse("{$table}.purchase_id exists");
        }
        expect(Schema::hasColumn('purchases', 'balance_kobo'))->toBeFalse();
    });

    it('enforces one purchase per customer and idempotency key, at database level', function () {
        $user = User::factory()->create();
        $first = puPurchase(['user' => $user]);

        expect(fn () => puPurchase(['user' => $user, 'idempotency_key' => $first->idempotency_key]))->toThrow(UniqueConstraintViolationException::class);
        expect(puPurchase(['idempotency_key' => $first->idempotency_key])->exists)->toBeTrue(); // another customer may use the same key
    });

    it('links a debit or a refund transaction to at most one purchase', function () {
        $first = puPurchase();
        $debit = puTransaction($first);
        $first->forceFill(['debit_transaction_id' => $debit])->save();

        // Same customer and wallet, so only the unique index (not the wallet guard) stands in the way.
        $second = puPurchase(['user' => $first->user]);
        expect(fn () => $second->forceFill(['debit_transaction_id' => $debit])->save())->toThrow(UniqueConstraintViolationException::class);
    });

    it('allows each route at most once per purchase and unique request references', function () {
        $purchase = puPurchase();
        $route = puRoute($purchase->plan);
        $attempt = puAttempt($purchase, $route);

        expect(fn () => puAttempt($purchase, $route, 2))->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => puAttempt($purchase, puRoute($purchase->plan, 2), 1))->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => puAttempt(puPurchase([], $purchase->plan), $route, 1, ['request_reference' => $attempt->request_reference]))->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('purchase guards', function () {
    it('is always created pending', function (PurchaseStatus $status) {
        expect(fn () => puPurchase(['status' => $status]))->toThrow(PurchaseException::class);
    })->with([PurchaseStatus::Successful, PurchaseStatus::Failed, PurchaseStatus::Review]);

    it('never changes its snapshot fields', function (string $field, mixed $value) {
        $purchase = puPurchase();

        expect(fn () => $purchase->forceFill([$field => $value])->save())->toThrow(LogicException::class);
    })->with([
        ['reference', 'PUR-OTHER'], ['amount_kobo', 1], ['recipient', '08099999999'], ['plan_name', 'Other'], ['service_name', 'Other'],
        ['user_type', 'vendor'], ['face_value_kobo', 100], ['fee_kobo', 5], ['idempotency_key', 'other'], ['request_fingerprint', str_repeat('a', 64)],
    ]);

    it('sets the debit, refund and successful attempt at most once', function () {
        $purchase = puPurchase();
        $purchase->forceFill(['debit_transaction_id' => puTransaction($purchase)])->save();

        expect(fn () => $purchase->fresh()->forceFill(['debit_transaction_id' => puTransaction(puPurchase())])->save())->toThrow(LogicException::class);
    });

    it('follows the approved status changes only', function (PurchaseStatus $from, PurchaseStatus $to, bool $allowed) {
        expect($from->canTransitionTo($to))->toBe($allowed);
    })->with([
        [PurchaseStatus::Pending, PurchaseStatus::Successful, true],
        [PurchaseStatus::Pending, PurchaseStatus::Failed, true],
        [PurchaseStatus::Pending, PurchaseStatus::Review, true],
        [PurchaseStatus::Review, PurchaseStatus::Successful, true],
        [PurchaseStatus::Review, PurchaseStatus::Failed, true],
        [PurchaseStatus::Review, PurchaseStatus::Pending, false],
        [PurchaseStatus::Successful, PurchaseStatus::Failed, false],
        [PurchaseStatus::Successful, PurchaseStatus::Pending, false],
        [PurchaseStatus::Successful, PurchaseStatus::Review, false],
        [PurchaseStatus::Failed, PurchaseStatus::Successful, false],
        [PurchaseStatus::Failed, PurchaseStatus::Pending, false],
        [PurchaseStatus::Failed, PurchaseStatus::Review, false],
    ]);

    it('becomes failed only together with its refund', function () {
        $purchase = puPurchase();

        expect(fn () => $purchase->forceFill(['status' => PurchaseStatus::Failed])->save())->toThrow(PurchaseException::class);
        expect(fn () => $purchase->fresh()->forceFill(['refund_transaction_id' => puTransaction($purchase, 'credit')])->save())->toThrow(PurchaseException::class);

        $purchase = puDebited(puPurchase());
        $purchase->forceFill(['status' => PurchaseStatus::Failed, 'refund_transaction_id' => puTransaction($purchase, 'credit')])->save();
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Failed)->and($purchase->isFinal())->toBeTrue();
    });

    it('becomes successful only with the delivering attempt', function () {
        $purchase = puDebited(puPurchase());
        $attempt = puAttempt($purchase, puRoute($purchase->plan));
        $attempt->forceFill(['status' => PurchaseAttemptStatus::Succeeded])->save();

        expect(fn () => $purchase->forceFill(['status' => PurchaseStatus::Successful])->save())->toThrow(PurchaseException::class);
        expect(fn () => $purchase->fresh()->forceFill(['successful_attempt_id' => $attempt->id])->save())->toThrow(PurchaseException::class);

        $purchase->fresh()->forceFill(['status' => PurchaseStatus::Successful, 'successful_attempt_id' => $attempt->id, 'cost_kobo' => 45_000, 'margin_kobo' => 5_000])->save();
        expect($purchase->fresh()->successfulAttempt->is($attempt))->toBeTrue();
    });

    it('never leaves a final status', function () {
        $purchase = puDebited(puPurchase());
        $purchase->forceFill(['status' => PurchaseStatus::Failed, 'refund_transaction_id' => puTransaction($purchase, 'credit')])->save();

        expect(fn () => $purchase->fresh()->forceFill(['status' => PurchaseStatus::Review])->save())->toThrow(LogicException::class);
    });

    it('is never deleted', function () {
        expect(fn () => puPurchase()->delete())->toThrow(LogicException::class);
    });

    it('fingerprints the requested details', function () {
        expect(Purchase::fingerprint(1, '08012345678', null))->toBe(Purchase::fingerprint(1, '08012345678', null))
            ->and(Purchase::fingerprint(1, '08012345678', null))->toHaveLength(64)
            ->not->toBe(Purchase::fingerprint(2, '08012345678', null))
            ->not->toBe(Purchase::fingerprint(1, '08012345679', null))
            ->not->toBe(Purchase::fingerprint(1, '08012345678', 10_000));
    });
});

describe('attempt guards', function () {
    it('is always created started', function () {
        $purchase = puPurchase();

        expect(fn () => puAttempt($purchase, puRoute($purchase->plan), 1, ['status' => PurchaseAttemptStatus::Succeeded]))->toThrow(PurchaseException::class);
    });

    it('follows the approved attempt status changes only', function (PurchaseAttemptStatus $from, PurchaseAttemptStatus $to, bool $allowed) {
        expect($from->canTransitionTo($to))->toBe($allowed);
    })->with([
        [PurchaseAttemptStatus::Started, PurchaseAttemptStatus::Succeeded, true],
        [PurchaseAttemptStatus::Started, PurchaseAttemptStatus::FailedDefinite, true],
        [PurchaseAttemptStatus::Started, PurchaseAttemptStatus::Unknown, true],
        [PurchaseAttemptStatus::Unknown, PurchaseAttemptStatus::Succeeded, true],
        [PurchaseAttemptStatus::Unknown, PurchaseAttemptStatus::FailedDefinite, true],
        [PurchaseAttemptStatus::Unknown, PurchaseAttemptStatus::Started, false],
        [PurchaseAttemptStatus::Succeeded, PurchaseAttemptStatus::FailedDefinite, false],
        [PurchaseAttemptStatus::Succeeded, PurchaseAttemptStatus::Unknown, false],
        [PurchaseAttemptStatus::FailedDefinite, PurchaseAttemptStatus::Succeeded, false],
        [PurchaseAttemptStatus::FailedDefinite, PurchaseAttemptStatus::Unknown, false],
    ]);

    it('enforces the attempt transitions on save', function () {
        $purchase = puPurchase();
        $attempt = puAttempt($purchase, puRoute($purchase->plan));
        $attempt->forceFill(['status' => PurchaseAttemptStatus::Unknown])->save();
        $attempt->fresh()->forceFill(['status' => PurchaseAttemptStatus::FailedDefinite])->save();

        expect(fn () => $attempt->fresh()->forceFill(['status' => PurchaseAttemptStatus::Succeeded])->save())->toThrow(LogicException::class);
    });

    it('never changes its route snapshot or request reference', function (string $field, mixed $value) {
        $purchase = puPurchase();
        $attempt = puAttempt($purchase, puRoute($purchase->plan));

        expect(fn () => $attempt->forceFill([$field => $value])->save())->toThrow(LogicException::class);
    })->with([['request_reference', 'PRA-OTHER'], ['provider_plan_code', 'X'], ['cost_kobo', 1], ['route_priority', 9], ['attempt_number', 3]]);

    it('sets the provider reference at most once and is never deleted', function () {
        $purchase = puPurchase();
        $attempt = puAttempt($purchase, puRoute($purchase->plan));
        $attempt->forceFill(['provider_reference' => 'P-1'])->save();

        expect(fn () => $attempt->fresh()->forceFill(['provider_reference' => 'P-2'])->save())->toThrow(LogicException::class)
            ->and(fn () => $attempt->fresh()->delete())->toThrow(LogicException::class);
    });
});

it('keeps purchase history append-only', function () {
    $change = tap((new PurchaseStatusChange)->forceFill(['purchase_id' => puPurchase()->id, 'old_status' => null,
        'new_status' => PurchaseStatus::Pending, 'source' => PurchaseSource::Customer]))->save();

    expect($change->fresh()->source)->toBe(PurchaseSource::Customer)
        ->and(fn () => $change->forceFill(['note' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $change->delete())->toThrow(LogicException::class);
});

describe('wallet types', function () {
    it('adds the purchase transaction type and purchase debit and refund ledger types', function () {
        expect(TransactionType::Purchase->value)->toBe('purchase')->and(TransactionType::Purchase->label())->toBe('Purchase')
            ->and(LedgerEntryType::PurchaseDebit->value)->toBe('purchase_debit')
            ->and(LedgerEntryType::PurchaseRefund->value)->toBe('purchase_refund');
    });

    it('posts purchase debits and refunds through WalletService with the purchase reference as key', function () {
        $purchase = puPurchase();
        $debit = puTransaction($purchase);
        $refund = puTransaction($purchase, 'credit');

        expect(WalletLedgerEntry::where('transaction_id', $debit)->sole()->entry_type)->toBe(LedgerEntryType::PurchaseDebit)
            ->and(WalletLedgerEntry::where('transaction_id', $refund)->sole()->entry_type)->toBe(LedgerEntryType::PurchaseRefund)
            ->and($purchase->wallet->fresh()->balance_kobo)->toBe(100_000)
            ->and(Artisan::call('wallet:verify'))->toBe(0);
    });
});

describe('permissions', function () {
    it('adds purchases.view and purchases.manage to the Purchases module, Super Admin only by default', function () {
        expect(SystemPermission::PurchasesView->module())->toBe(PermissionModule::Purchases)
            ->and(SystemPermission::PurchasesManage->module())->toBe(PermissionModule::Purchases)
            ->and(PermissionModule::Purchases->isBuilt())->toBeFalse();

        foreach (SystemRole::cases() as $role) {
            $staff = SystemUser::factory()->create();
            $staff->assignRole($role->value);
            foreach (['purchases.view', 'purchases.manage'] as $permission) {
                expect($staff->can($permission))->toBe($role === SystemRole::SuperAdmin, "{$role->value} {$permission}");
            }
        }
    });

    it('grants both permissions to an existing Super Admin role when migrating an existing database', function () {
        Permission::whereIn('name', ['purchases.view', 'purchases.manage'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        (require database_path('migrations/2026_10_03_170100_create_purchase_permissions.php'))->up();

        expect(Role::findByName('super-admin', 'admin')->hasPermissionTo('purchases.view'))->toBeTrue()
            ->and(Role::findByName('super-admin', 'admin')->hasPermissionTo('purchases.manage'))->toBeTrue()
            ->and(Role::findByName('manager', 'admin')->hasPermissionTo('purchases.view'))->toBeFalse();
    });

    it('shows the Purchases module under Operations as a placeholder until it is built', function () {
        $staff = SystemUser::factory()->create();
        $staff->assignRole(SystemRole::SuperAdmin->value);

        $this->actingAs($staff, 'admin')->get('/admin/purchases')->assertOk()
            ->assertSee('data-placeholder="purchases"', false)->assertSee('Phase 10');
        $this->get('/admin')->assertSee('data-nav="purchases"', false);

        $manager = SystemUser::factory()->create();
        $manager->assignRole(SystemRole::Manager->value);
        $this->actingAs($manager, 'admin')->get('/admin/purchases')->assertForbidden();
    });
});

it('adds no purchase engine, provider execution, customer purchase routes or adapters yet', function () {
    $routes = collect(Route::getRoutes())->map->uri();

    expect($routes->filter(fn ($uri) => preg_match('/(buy|purchase|vend|order)/i', $uri) && $uri !== 'admin/purchases')->values()->all())->toBe([])
        ->and(class_exists('App\\Services\\Purchases\\PurchaseService'))->toBeFalse()
        ->and(config('providers.drivers'))->toBe([]); // the adapter registry (CP2) ships with no adapters
});
