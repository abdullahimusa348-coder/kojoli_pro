<?php

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\PurchaseIdentityRecipient;
use App\Models\Service;
use App\Support\Enums\UserType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 11 CP1 migrations on SQLite, the test database (always migrated while
 * empty): structure, rollback to exactly the Phase 10 schema and back, and
 * refused rollbacks while NIN/BVN data exists. Rolling back over historical
 * Phase 10 data is tested on MariaDB (tests/Concurrency/PurchaseIdentityTest.php):
 * SQLite rebuilds a table to change a column, which needs an empty table.
 */

const PRM_CP1 = ['2026_10_04_100000_add_recipient_type_to_purchases', '2026_10_04_100100_create_purchase_identity_recipients_table'];

/** The last Phase 10 migration: the Phase 10 schema is everything up to and including it, whatever later checkpoints add. */
const PRM_PHASE10_LAST = '2026_10_03_170200_add_purchase_integrity_constraints';

/** Rolls back every migration newer than $last, newest first (nothing when there is none). */
function prmRollBackAfter(string $last): void
{
    $steps = DB::table('migrations')->where('migration', '>', $last)->count();
    if ($steps > 0) {
        Artisan::call('migrate:rollback', ['--step' => $steps]);
    }
}

/** The table list, and the columns, indexes and foreign keys of the two CP1 tables, as the schema builder reports them. */
function prmSchema(?string $connection = null): array
{
    $schema = Schema::connection($connection);
    $tables = collect($schema->getTables())->pluck('name')->sort()->values()->all();
    $sorted = fn (array $items) => collect($items)->sortBy(fn (array $item) => json_encode($item))->values()->all();
    $describe = fn (string $table) => in_array($table, $tables, true)
        ? ['columns' => $schema->getColumns($table), 'indexes' => $sorted($schema->getIndexes($table)), 'foreign_keys' => $sorted($schema->getForeignKeys($table))]
        : null;

    return ['tables' => $tables, 'purchases' => $describe('purchases'), 'purchase_identity_recipients' => $describe('purchase_identity_recipients')];
}

/** The Phase 10 schema: every migration up to the Phase 10 boundary, run on a separate empty in-memory SQLite database. */
function prmPhase10Schema(): array
{
    config(['database.connections.prm_phase10' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $paths = collect(File::files(database_path('migrations')))
        ->filter(fn ($file) => $file->getFilenameWithoutExtension() <= PRM_PHASE10_LAST)
        ->reject(fn ($file) => in_array($file->getFilenameWithoutExtension(), PRM_CP1, true))
        ->map(fn ($file) => $file->getPathname())->values()->all();
    Artisan::call('migrate', ['--database' => 'prm_phase10', '--path' => $paths, '--realpath' => true]);

    return prmSchema('prm_phase10');
}

/** An available fixed-price NIN plan with an executable FakeProvider route. */
function prmNinPlan(): Plan
{
    $service = Service::factory()->create(['name' => 'NIN', 'slug' => 'nin']);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Test product', 'code' => 'nin-test-'.Str::lower(Str::random(6))]);
    $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Test plan', 'code' => $product->code.'-p', 'amount_type' => 'fixed']);
    PlanPrice::factory()->create(['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => 15_000]);
    puxRoute($plan);

    return $plan->fresh();
}

it('adds the recipient type with its default, nullable legacy columns, and the identity table with its keys', function () {
    $purchases = collect(Schema::getColumns('purchases'))->keyBy('name');
    $identity = collect(Schema::getColumns('purchase_identity_recipients'))->keyBy('name');
    $indexes = collect(Schema::getIndexes('purchase_identity_recipients'))->keyBy('name');
    $foreign = Schema::getForeignKeys('purchase_identity_recipients');

    expect($purchases['recipient_type'])->toMatchArray(['type_name' => 'varchar', 'nullable' => false, 'default' => "'phone'"])
        ->and($purchases['recipient'])->toMatchArray(['type_name' => 'varchar', 'nullable' => true, 'default' => null])
        ->and($purchases['request_fingerprint'])->toMatchArray(['type_name' => 'varchar', 'nullable' => true, 'default' => null])
        ->and($identity->keys()->all())->toBe(['id', 'purchase_id', 'encrypted_value', 'masked_value', 'lookup_hash', 'keyed_fingerprint', 'consented_at', 'created_at'])
        ->and($identity->map(fn (array $column) => $column['nullable'])->unique()->all())->toBe(['id' => false])
        ->and($identity['encrypted_value']['type_name'])->toBe('text')
        ->and($identity['consented_at']['type_name'])->toBe('datetime')
        ->and($identity['created_at']['default'])->toBe('CURRENT_TIMESTAMP')
        ->and(Schema::hasColumn('purchase_identity_recipients', 'updated_at'))->toBeFalse()
        ->and($indexes['purchase_identity_recipients_purchase_id_unique'])->toMatchArray(['columns' => ['purchase_id'], 'unique' => true])
        ->and($indexes['purchase_identity_recipients_lookup_hash_index'])->toMatchArray(['columns' => ['lookup_hash'], 'unique' => false])
        ->and($foreign)->toHaveCount(1)
        ->and($foreign[0])->toMatchArray(['columns' => ['purchase_id'], 'foreign_table' => 'purchases', 'foreign_columns' => ['id'], 'on_delete' => 'restrict']);
});

it('rolls back to exactly the Phase 10 schema, and migrates again', function () {
    $cp1 = prmSchema();
    $phase10 = prmPhase10Schema();
    expect($phase10['purchase_identity_recipients'])->toBeNull()
        ->and(collect($phase10['purchases']['columns'])->pluck('name'))->not->toContain('recipient_type');

    prmRollBackAfter(PRM_PHASE10_LAST); // CP1 and every later checkpoint, newest first

    expect(prmSchema())->toEqual($phase10)
        ->and(DB::table('migrations')->whereIn('migration', PRM_CP1)->count())->toBe(0)
        ->and(DB::table('migrations')->where('migration', '>', PRM_PHASE10_LAST)->count())->toBe(0);

    Artisan::call('migrate');

    expect(prmSchema())->toEqual($cp1)
        ->and(DB::table('migrations')->whereIn('migration', PRM_CP1)->count())->toBe(2);
});

it('refuses to roll back while NIN/BVN data exists, changing nothing it refuses', function () {
    puxDrivers();
    FakeProvider::$services = ['nin'];
    $purchase = puxService()->create(puxCustomer(100_000), prmNinPlan(), (string) random_int(10_000_000_000, 99_999_999_999), null, 'k', null, true);
    prmRollBackAfter(PRM_CP1[1]); // later checkpoints first (nothing of theirs blocks it here), so CP1's two migrations are the newest
    $before = prmSchema();

    expect(fn () => Artisan::call('migrate:rollback', ['--step' => 2]))
        ->toThrow(RuntimeException::class, 'Refusing to roll back: NIN/BVN purchases have identity recipients, which would be lost. Nothing was changed.');
    expect(prmSchema())->toEqual($before)
        ->and(DB::table('migrations')->whereIn('migration', PRM_CP1)->count())->toBe(2)
        ->and(PurchaseIdentityRecipient::count())->toBe(1);

    // Even with its identity recipient gone, the NIN purchase itself stops the purchases migration.
    DB::table('purchase_identity_recipients')->delete();
    expect(fn () => Artisan::call('migrate:rollback', ['--step' => 2]))
        ->toThrow(RuntimeException::class, 'Refusing to roll back: 1 purchase(s) are not phone purchases (NIN/BVN).');
    expect(Schema::hasTable('purchase_identity_recipients'))->toBeFalse()
        ->and(prmSchema()['purchases'])->toEqual($before['purchases'])
        ->and(DB::table('migrations')->where('migration', PRM_CP1[0])->exists())->toBeTrue()
        ->and(DB::table('purchases')->where('id', $purchase->id)->value('recipient_type'))->toBe('nin');
});
