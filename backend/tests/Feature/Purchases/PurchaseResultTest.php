<?php

use App\Actions\Admin\Purchases\RecheckPurchase;
use App\Exceptions\Purchases\PurchaseException;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\PurchaseResult;
use App\Models\PurchaseStatusChange;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderResultFields;
use App\Support\Enums\UserType;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Purchases\RecipientType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;
use Tests\Support\Providers\ResultProbeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 11 CP2: NIN/BVN purchase results. A NIN/BVN purchase succeeds only
 * with the result its provider delivered, stored once (encrypted, with the
 * purchased number masked) for the delivering attempt, in the transaction
 * that marks it successful. A success without it stays unclear
 * (result_missing), never refunded or failed over; a purchase with a result
 * is never refunded. Phone purchases (Data, Airtime) keep the exact Phase 10
 * behaviour and never have a result. Numbers and result values are neutral
 * fixtures generated when the tests run (D1); nothing here is identity data.
 */

const PRT_MIGRATION = '2026_10_04_120000_create_purchase_results_table';

/** The last CP1 migration: everything newer is CP2 or later. */
const PRT_CP1_LAST = '2026_10_04_100100_create_purchase_identity_recipients_table';

beforeEach(function () {
    puxDrivers();
    FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn'];
    ResultProbeProvider::reset();
    Http::preventStrayRequests();
});

dataset('prt identity types', ['NIN' => [RecipientType::Nin], 'BVN' => [RecipientType::Bvn]]);

/** A random 11-digit number, generated when the test runs. */
function prtNumber(): string
{
    return (string) random_int(10_000_000_000, 99_999_999_999);
}

/** An available fixed-price NIN or BVN plan (15,000 kobo) with $routes executable routes (cost 10,000 kobo). */
function prtPlan(RecipientType $type, int $routes = 1, string $driver = 'fake-provider'): Plan
{
    $service = Service::where('slug', $type->value)->first() ?? Service::factory()->create(['name' => $type->label(), 'slug' => $type->value]);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Test product', 'code' => $type->value.'-test-'.Str::lower(Str::random(6))]);
    $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Test plan', 'code' => $product->code.'-p', 'amount_type' => 'fixed']);
    PlanPrice::factory()->create(['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => 15_000]);
    for ($priority = 1; $priority <= $routes; $priority++) {
        puxRoute($plan, $priority, ['cost_type' => 'fixed', 'cost_kobo' => 10_000], $driver);
    }

    return $plan->fresh();
}

function prtBuy(User $user, Plan $plan, string $number): Purchase
{
    return puxService()->purchase($user, $plan, $number, null, (string) Str::uuid(), null, true);
}

/** A NIN/BVN purchase whose provider call was unclear (no answer), due for its re-check; its second route is never tried. */
function prtUnclear(RecipientType $type, ?User $user = null, ?string $number = null): Purchase
{
    FakeProvider::$purchaseScript = ['timeout'];
    $purchase = prtBuy($user ?? puxCustomer(100_000), prtPlan($type, 2), $number ?? prtNumber());
    $purchase->forceFill(['next_check_at' => now()->subMinute()])->save();

    return $purchase->fresh();
}

/** @return list<string> the values of each set */
function prtValues(ProviderResultFields ...$sets): array
{
    return array_merge(...array_map(fn (ProviderResultFields $fields) => array_column($fields->all(), 'value'), $sets));
}

/** A result row written straight into the table (no model guards), as damaged or tampered data would be. */
function prtInsertResult(Purchase $purchase, int $attemptId, ?array $fields = null, ?string $encrypted = null, ?int $count = null): void
{
    $fields ??= FakeProvider::fixtureFields()->all();
    DB::table('purchase_results')->insert(['purchase_id' => $purchase->id, 'purchase_attempt_id' => $attemptId,
        'encrypted_fields' => $encrypted ?? Crypt::encryptString(json_encode($fields)), 'field_count' => $count ?? count($fields), 'created_at' => now()]);
}

/** Every row of every table, as JSON, to scan for plain values. */
function prtDatabaseDump(): string
{
    return collect(DB::select("select name from sqlite_master where type = 'table'"))->pluck('name')
        ->map(fn (string $table) => $table.': '.json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode("\n");
}

/** Records every log line (message and context) written from now on. */
function prtRecordLogs(): ArrayObject
{
    $lines = new ArrayObject;
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $lines->append(
        $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

    return $lines;
}

/** Makes $key the app key and $previous the APP_PREVIOUS_KEYS, as a deployment would. */
function prtUseKeys(string $key, array $previous = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function prtNewKey(): string
{
    return 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
}

/** After an app key change without APP_PREVIOUS_KEYS the provider credentials cannot be read either: re-entered under the new key, as an operator must. */
function prtReenterCredentials(): void
{
    DB::table('provider_credentials')->update(['value' => Crypt::encryptString(PUX_KEY)]);
}

function prtStaff(): SystemUser
{
    (new RolesAndPermissionsSeeder)->run();
    $staff = SystemUser::factory()->create();
    $staff->assignRole('super-admin');

    return $staff;
}

/** The exact refusal message, or null when nothing was refused. */
function prtRefusal(Closure $call): ?string
{
    try {
        $call();
    } catch (PurchaseException $e) {
        return $e->getMessage();
    }

    return null;
}

/** Wallet, ledger and purchase integrity checks both pass. */
function prtClean(): void
{
    expect(Artisan::call('wallet:verify'))->toBe(0)
        ->and(Artisan::call('purchases:verify'))->toBe(0, Artisan::output());
}

/** Columns, indexes and foreign keys of every table, as the schema builder reports them. */
function prtSchema(): array
{
    $sorted = fn (array $items) => collect($items)->sortBy(fn (array $item) => json_encode($item))->values()->all();

    return collect(Schema::getTables())->pluck('name')->sort()->values()->mapWithKeys(fn (string $table) => [$table => [
        'columns' => Schema::getColumns($table), 'indexes' => $sorted(Schema::getIndexes($table)), 'foreign_keys' => $sorted(Schema::getForeignKeys($table)),
    ]])->all();
}

describe('storage', function () {
    it('stores the delivered result once, encrypted, for the delivering attempt of that purchase', function (RecipientType $type) {
        $this->freezeTime();
        $fields = FakeProvider::fixtureFields(3);
        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [$fields];

        $purchase = prtBuy(puxCustomer(100_000), prtPlan($type), prtNumber());

        $row = DB::table('purchase_results')->sole();
        $attempt = $purchase->attempts->sole();
        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and($purchase->successful_attempt_id)->toBe($attempt->id)
            ->and((int) $row->purchase_id)->toBe($purchase->id)
            ->and((int) $row->purchase_attempt_id)->toBe($attempt->id)
            ->and((int) $row->field_count)->toBe(3)
            ->and($row->created_at)->toBe(now()->format('Y-m-d H:i:s'))
            ->and(json_decode(Crypt::decryptString($row->encrypted_fields), true))->toBe($fields->all())
            ->and($purchase->result->fields())->toBe($fields->all())
            ->and($purchase->result->canBeRead())->toBeTrue()
            ->and(PurchaseStatusChange::where('purchase_id', $purchase->id)->where('new_status', 'successful')->count())->toBe(1)
            ->and(FakeProvider::$resultScript)->toBe([]);
        foreach (prtValues($fields) as $value) {
            expect($row->encrypted_fields)->not->toContain($value);
        }
        prtClean();
    })->with('prt identity types');

    it('stores the result and the success together, or neither', function (string $failing) {
        $user = puxCustomer(100_000);
        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [FakeProvider::fixtureFields()];
        $failing === 'success'
            ? Purchase::saving(fn (Purchase $p) => $p->status === PurchaseStatus::Successful ? throw new RuntimeException('Simulated failure.') : null)
            : PurchaseResult::creating(fn () => throw new RuntimeException('Simulated failure.'));

        expect(fn () => prtBuy($user, prtPlan(RecipientType::Nin), prtNumber()))->toThrow(RuntimeException::class, 'Simulated failure.');

        $purchase = Purchase::sole();
        expect($purchase->status)->toBe(PurchaseStatus::Pending)
            ->and($purchase->successful_attempt_id)->toBeNull()
            ->and(PurchaseResult::count())->toBe(0)
            ->and($purchase->attempts->sole()->status)->toBe(PurchaseAttemptStatus::Started)
            ->and(PurchaseStatusChange::where('purchase_id', $purchase->id)->where('new_status', 'successful')->count())->toBe(0)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
    })->with(['marking the purchase successful fails' => ['success'], 'storing the result fails' => ['result']]);

    it('never serialises the stored result', function () {
        $fields = FakeProvider::fixtureFields();
        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [$fields];
        $purchase = prtBuy(puxCustomer(100_000), prtPlan(RecipientType::Nin), prtNumber());
        $result = $purchase->result;
        $loaded = Purchase::with('result')->findOrFail($purchase->id);
        $keys = ['id', 'purchase_id', 'purchase_attempt_id', 'field_count', 'created_at'];

        expect(array_keys($result->toArray()))->toEqualCanonicalizing($keys)
            ->and(array_keys(json_decode($result->toJson(), true)))->toEqualCanonicalizing($keys)
            ->and(array_keys($loaded->toArray()['result']))->toEqualCanonicalizing($keys);
        $printed = $loaded->toJson().print_r($loaded->toArray(), true).$result;
        foreach (prtValues($fields) as $value) {
            expect($printed)->not->toContain($value);
        }
    });

    it('replaces the purchased number with its mask wherever it appears, however it is written', function (RecipientType $type) {
        $number = prtNumber();
        $other = prtNumber();
        $spaced = implode(' ', str_split($number, 4));
        $dashed = implode('-', str_split($number, 4));
        $dotted = implode('.', str_split($number, 3));
        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [new ProviderResultFields([
            ['key' => 'fixture_1', 'label' => 'Fixture 1', 'value' => $number],
            ['key' => 'fixture_2', 'label' => 'Fixture '.$number, 'value' => "fixture {$spaced} and {$dashed}"],
            ['key' => 'fixture_3', 'label' => 'Fixture 3', 'value' => "{$dotted}/{$number}{$number}"],
            ['key' => 'fixture_4', 'label' => 'Fixture 4', 'value' => "other {$other}"],
        ])];

        $purchase = prtBuy(puxCustomer(100_000), prtPlan($type), $number);

        $mask = '•••••••'.substr($number, -4);
        expect($purchase->result->fields())->toBe([
            ['key' => 'fixture_1', 'label' => 'Fixture 1', 'value' => $mask],
            ['key' => 'fixture_2', 'label' => 'Fixture '.$mask, 'value' => "fixture {$mask} and {$mask}"],
            ['key' => 'fixture_3', 'label' => 'Fixture 3', 'value' => "{$mask}/{$mask}{$mask}"],
            ['key' => 'fixture_4', 'label' => 'Fixture 4', 'value' => "other {$other}"],
        ])
            ->and(Crypt::decryptString(DB::table('purchase_results')->value('encrypted_fields')))->not->toContain($number)
            ->and(prtDatabaseDump())->not->toContain($number);
        prtClean();
    })->with('prt identity types');

    it('reads results stored under the previous app key, and purchases:verify reports them once that key is dropped', function () {
        $plan = prtPlan(RecipientType::Bvn);
        $fields = FakeProvider::fixtureFields();
        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [$fields];
        $oldKey = config('app.key');
        $before = prtBuy(puxCustomer(100_000), $plan, prtNumber());

        prtUseKeys(prtNewKey(), [$oldKey]);

        expect(PurchaseResult::sole()->fields())->toBe($fields->all())
            ->and(PurchaseResult::sole()->canBeRead())->toBeTrue();
        prtClean();
        $later = FakeProvider::fixtureFields();
        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [$later];
        $after = prtBuy(puxCustomer(100_000), $plan, prtNumber());
        expect($after->result->fields())->toBe($later->all());

        prtUseKeys(config('app.key')); // the old key is dropped from APP_PREVIOUS_KEYS

        expect(PurchaseResult::where('purchase_id', $before->id)->sole()->fields())->toBeNull()
            ->and(PurchaseResult::where('purchase_id', $before->id)->sole()->canBeRead())->toBeFalse()
            ->and(PurchaseResult::where('purchase_id', $after->id)->sole()->fields())->toBe($later->all())
            ->and(Artisan::call('purchases:verify'))->toBe(1);
        $output = Artisan::output();
        expect($output)->toContain("Purchase {$before->reference} (successful): its result cannot be read (app key not in APP_PREVIOUS_KEYS, or a damaged or miscounted value).")
            ->not->toContain($after->reference);
        foreach (prtValues($fields, $later) as $value) {
            expect($output)->not->toContain($value);
        }
    });
});

describe('engine', function () {
    it('treats a success without its result as unclear (result_missing), never refunding or failing over, until a re-check delivers it', function (RecipientType $type) {
        $user = puxCustomer(100_000);
        FakeProvider::$purchaseScript = ['succeeded']; // no result fields

        $purchase = prtBuy($user, prtPlan($type, 2), prtNumber());

        $attempt = $purchase->attempts->sole();
        expect($purchase->status)->toBe(PurchaseStatus::Pending)
            ->and($attempt->status)->toBe(PurchaseAttemptStatus::Unknown)
            ->and($attempt->error_code)->toBe('result_missing')
            ->and($attempt->error_message)->toBe('The provider reported success without the result.')
            ->and($attempt->provider_reference)->toBe('FP-'.$attempt->request_reference)
            ->and($purchase->next_check_at)->not->toBeNull()
            ->and($purchase->refund_transaction_id)->toBeNull()
            ->and(PurchaseResult::count())->toBe(0)
            ->and(FakeProvider::$calls)->toHaveCount(1)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
        prtClean();

        puxService()->execute($purchase->fresh()); // an unclear attempt is only ever re-checked
        expect(FakeProvider::$calls)->toHaveCount(1)->and($purchase->attempts()->count())->toBe(1);

        $this->travel(3)->minutes();
        $fields = FakeProvider::fixtureFields();
        FakeProvider::$queryScript = ['succeeded'];
        FakeProvider::$resultScript = [$fields];
        Artisan::call('purchases:reconcile');

        $purchase->refresh();
        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and($purchase->successful_attempt_id)->toBe($attempt->id)
            ->and($purchase->result->purchase_attempt_id)->toBe($attempt->id)
            ->and($purchase->result->fields())->toBe($fields->all())
            ->and($attempt->fresh()->status)->toBe(PurchaseAttemptStatus::Succeeded)
            ->and($attempt->fresh()->error_code)->toBeNull()
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(0)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
        prtClean();
    })->with('prt identity types');

    it('keeps it unclear while re-checks report success without the result, moves it to review, and never refunds it', function () {
        $staff = prtStaff();
        $purchase = prtUnclear(RecipientType::Nin);
        FakeProvider::$queryScript = ['succeeded'];
        Artisan::call('purchases:reconcile');
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Pending)
            ->and($purchase->attempts()->sole()->error_code)->toBe('result_missing');

        $this->travel(25)->hours();
        FakeProvider::$queryScript = ['succeeded'];
        Artisan::call('purchases:reconcile');
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Review);

        FakeProvider::$queryScript = ['succeeded', 'succeeded'];
        app(RecheckPurchase::class)->handle($purchase->fresh(), $staff);
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Review)
            ->and(PurchaseResult::count())->toBe(0);
        prtClean();

        $fields = FakeProvider::fixtureFields();
        FakeProvider::$resultScript = [$fields];
        app(RecheckPurchase::class)->handle($purchase->fresh(), $staff);

        $purchase->refresh();
        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and($purchase->result->fields())->toBe($fields->all())
            ->and(PurchaseStatusChange::where('purchase_id', $purchase->id)->where('new_status', 'successful')->sole()->changed_by)->toBe($staff->id)
            ->and(PurchaseAttempt::where('purchase_id', $purchase->id)->count())->toBe(1)
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(0);
        prtClean();
    });

    it('stores nothing and keeps the purchase unclear when a re-check delivers a result but the purchased number cannot be read', function (RecipientType $type, Closure $damage) {
        $logs = prtRecordLogs();
        $staff = prtStaff();
        $user = puxCustomer(100_000);
        $number = prtNumber();
        $purchase = prtUnclear($type, $user, $number);
        $attempt = $purchase->attempts()->sole();
        $damage($number);
        $fields = FakeProvider::fixtureFields();

        FakeProvider::$queryScript = ['succeeded', 'succeeded'];
        FakeProvider::$resultScript = [$fields, $fields]; // the provider delivers the result to the scheduled and to the staff re-check
        Artisan::call('purchases:reconcile');
        app(RecheckPurchase::class)->handle($purchase->fresh(), $staff);

        $purchase->refresh();
        $attempt->refresh();
        expect(FakeProvider::$resultScript)->toBe([])
            ->and($purchase->status)->toBe(PurchaseStatus::Pending)
            ->and($purchase->successful_attempt_id)->toBeNull()
            ->and($purchase->completed_at)->toBeNull()
            ->and($purchase->check_count)->toBe(2)
            ->and($purchase->next_check_at)->not->toBeNull()
            ->and($attempt->status)->toBe(PurchaseAttemptStatus::Unknown)
            ->and($attempt->error_code)->toBe('recipient_unavailable')
            ->and($attempt->error_message)->toBe('The result was not stored: the purchased number could not be read.')
            ->and(PurchaseResult::count())->toBe(0)
            ->and(PurchaseStatusChange::where('purchase_id', $purchase->id)->whereIn('new_status', ['successful', 'failed', 'review'])->count())->toBe(0)
            ->and($purchase->refund_transaction_id)->toBeNull()
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(0)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000)
            ->and(array_values(array_filter($logs->getArrayCopy(), fn (string $line) => str_contains($line, 'recipient'))))
            ->toBe(array_fill(0, 2, 'Purchase recipient unavailable {"purchase":"'.$purchase->reference.'"}'));
        $everything = implode("\n", $logs->getArrayCopy())."\n".prtDatabaseDump();
        foreach ([$number, ...prtValues($fields)] as $secret) {
            expect($everything)->not->toContain($secret);
        }
    })->with('prt identity types')->with([
        'app key changed without APP_PREVIOUS_KEYS' => [function () {
            prtUseKeys(prtNewKey());
            prtReenterCredentials();
        }],
        'number encrypted with another key' => [fn (string $number) => DB::table('purchase_identity_recipients')->update(['encrypted_value' => (new Encrypter(
            Encrypter::generateKey(config('app.cipher')), config('app.cipher')))->encryptString($number)])],
        'identity recipient missing' => [fn () => DB::table('purchase_identity_recipients')->delete()],
    ]);

    it('delivers the result once the old app key is back in APP_PREVIOUS_KEYS', function () {
        $staff = prtStaff();
        $purchase = prtUnclear(RecipientType::Nin);
        $oldKey = config('app.key');
        prtUseKeys(prtNewKey());
        prtReenterCredentials();
        FakeProvider::$queryScript = ['succeeded'];
        FakeProvider::$resultScript = [FakeProvider::fixtureFields()];
        app(RecheckPurchase::class)->handle($purchase->fresh(), $staff);
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Pending)
            ->and(PurchaseResult::count())->toBe(0);

        prtUseKeys(config('app.key'), [$oldKey]);
        $fields = FakeProvider::fixtureFields();
        FakeProvider::$queryScript = ['succeeded'];
        FakeProvider::$resultScript = [$fields];
        app(RecheckPurchase::class)->handle($purchase->fresh(), $staff);

        $purchase->refresh();
        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and($purchase->result->purchase_attempt_id)->toBe($purchase->attempts()->sole()->id)
            ->and($purchase->result->fields())->toBe($fields->all())
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(0);
        prtClean();
    });

    it('delivers the result after failing over a definite failure', function (RecipientType $type) {
        $fields = FakeProvider::fixtureFields();
        FakeProvider::$purchaseScript = ['failed_definite', 'succeeded'];
        FakeProvider::$resultScript = [$fields];

        $purchase = prtBuy(puxCustomer(100_000), prtPlan($type, 2), prtNumber());

        [$first, $second] = $purchase->attempts()->orderBy('attempt_number')->get()->all();
        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and($first->status)->toBe(PurchaseAttemptStatus::FailedDefinite)
            ->and($second->status)->toBe(PurchaseAttemptStatus::Succeeded)
            ->and($purchase->successful_attempt_id)->toBe($second->id)
            ->and(PurchaseResult::sole()->purchase_attempt_id)->toBe($second->id)
            ->and(PurchaseResult::sole()->fields())->toBe($fields->all());
        prtClean();
    })->with('prt identity types');

    it('stores no result for a definite failure (refunded once), an unclear outcome or review', function (RecipientType $type) {
        $staff = prtStaff();
        $plan = prtPlan($type, 2);
        $user = puxCustomer(100_000);
        FakeProvider::$resultScript = [FakeProvider::fixtureFields(), FakeProvider::fixtureFields()]; // never used: nothing succeeds
        FakeProvider::$purchaseScript = ['failed_definite', 'failed_definite'];
        $failed = prtBuy($user, $plan, prtNumber());
        FakeProvider::$purchaseScript = ['unknown'];
        $unclear = prtBuy($user, $plan, prtNumber());
        expect($unclear->status)->toBe(PurchaseStatus::Pending)->and(PurchaseResult::count())->toBe(0);

        $this->travel(25)->hours();
        FakeProvider::$queryScript = ['unknown'];
        Artisan::call('purchases:reconcile');
        expect($unclear->fresh()->status)->toBe(PurchaseStatus::Review)->and(PurchaseResult::count())->toBe(0);
        FakeProvider::$queryScript = ['failed_definite'];
        app(RecheckPurchase::class)->handle($unclear->fresh(), $staff);

        foreach ([$failed, $unclear] as $purchase) {
            expect($purchase->fresh()->status)->toBe(PurchaseStatus::Failed)
                ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(1);
        }
        expect(PurchaseResult::count())->toBe(0)
            ->and(FakeProvider::$resultScript)->toHaveCount(2)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(100_000);
        prtClean();
    })->with('prt identity types');

    it('never refunds a purchase that has a result, whatever the provider later says', function () {
        $staff = prtStaff();
        $user = puxCustomer(100_000);
        $purchase = prtUnclear(RecipientType::Nin, $user);
        $attempt = $purchase->attempts()->sole();
        prtInsertResult($purchase, $attempt->id); // a result beside an unclear attempt: not possible through the engine

        FakeProvider::$queryScript = ['failed_definite'];
        $stats = puxService()->reconcile();
        expect($stats['errors'])->toBe(1);
        FakeProvider::$queryScript = ['failed_definite'];
        expect(fn () => app(RecheckPurchase::class)->handle($purchase->fresh(), $staff))
            ->toThrow(PurchaseException::class, 'A purchase whose result was delivered is never refunded.');

        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Pending)
            ->and($purchase->fresh()->refund_transaction_id)->toBeNull()
            ->and($attempt->fresh()->status)->toBe(PurchaseAttemptStatus::Unknown)
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(0)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
    });

    it('keeps Data and Airtime exactly as in Phase 10, ignoring any result fields and storing none', function () {
        $data = puxPlan('data', 10_000);
        puxRoute($data, 1);
        puxRoute($data, 2);
        $airtime = puxPlan('airtime', 0, true);
        puxRoute($airtime);
        $user = puxCustomer(300_000);
        FakeProvider::$resultScript = [FakeProvider::fixtureFields(), FakeProvider::fixtureFields(), FakeProvider::fixtureFields()];
        FakeProvider::$purchaseScript = ['succeeded', 'failed_definite', 'succeeded', 'timeout'];

        $bundle = puxService()->purchase($user, $data, '08012345678', null, (string) Str::uuid());
        $failedOver = puxService()->purchase($user, $data, '08012345678', null, (string) Str::uuid());
        $topUp = puxService()->purchase($user, $airtime, '08030001111', 20_000, (string) Str::uuid());
        $this->travel(3)->minutes();
        FakeProvider::$queryScript = ['succeeded'];
        Artisan::call('purchases:reconcile');

        foreach ([$bundle, $failedOver, $topUp] as $purchase) {
            expect($purchase->fresh()->status)->toBe(PurchaseStatus::Successful)
                ->and($purchase->fresh()->recipient_type)->toBe(RecipientType::Phone)
                ->and($purchase->fresh()->successfulAttempt->error_code)->toBeNull();
        }
        expect(PurchaseResult::count())->toBe(0)
            ->and(FakeProvider::$resultScript)->toBe([]) // each success came with fields, and each was ignored
            ->and($failedOver->fresh()->successfulAttempt->route_priority)->toBe(2)
            ->and(collect(FakeProvider::$calls)->whereInstanceOf(ProviderPurchaseRequest::class)->pluck('recipientType')->unique()->values()->all())->toBe(['phone']);
        prtClean();
    });

    it('stores a result mapped by an HTTP adapter, and makes an invalid one unclear without storing it', function () {
        config(['providers.drivers' => ['fake-provider' => FakeProvider::class, 'result_probe' => ResultProbeProvider::class]]);
        $plan = prtPlan(RecipientType::Nin, 2, 'result_probe');
        $user = puxCustomer(100_000);
        $logs = prtRecordLogs();
        $text = 'FIXTURE-'.Str::upper(Str::random(16));
        $invalid = 'FIXTURE-'.Str::upper(Str::random(16));
        Http::fake(['https://api.result-probe.test/*' => Http::sequence()
            ->push(['status' => 'delivered', 'id' => 'RP-1', 'items' => [['name' => 'Fixture 1', 'text' => $text]]])
            ->push(['status' => 'delivered', 'id' => 'RP-2', 'items' => [['name' => 'Fixture 1', 'text' => $invalid."\x07"]]])]);

        $delivered = prtBuy($user, $plan, prtNumber());
        $unclear = prtBuy($user, $plan, prtNumber());

        expect($delivered->status)->toBe(PurchaseStatus::Successful)
            ->and($delivered->result->fields())->toBe([['key' => 'item_1', 'label' => 'Fixture 1', 'value' => $text]])
            ->and($unclear->status)->toBe(PurchaseStatus::Pending)
            ->and($unclear->attempts->sole()->status)->toBe(PurchaseAttemptStatus::Unknown)
            ->and($unclear->attempts->sole()->error_code)->toBe('adapter_error')
            ->and($unclear->refund_transaction_id)->toBeNull()
            ->and(PurchaseResult::count())->toBe(1)
            ->and($logs->getArrayCopy())->toBe(['Provider adapter error {"driver":"result_probe","call":"purchase","exception":"InvalidArgumentException"}'])
            ->and(prtDatabaseDump())->not->toContain($text)->not->toContain($invalid);
        prtClean();
    });
});

describe('model guards', function () {
    it('accepts a result only for a NIN/BVN purchase being settled, from its own succeeded attempt, with valid fields', function () {
        $user = puxCustomer(300_000);
        $nin = prtUnclear(RecipientType::Nin, $user);
        $bvn = prtUnclear(RecipientType::Bvn, $user);
        [$ninAttempt, $bvnAttempt] = [$nin->attempts()->sole(), $bvn->attempts()->sole()];
        $data = puxPlan('data', 10_000);
        puxRoute($data);
        FakeProvider::$purchaseScript = ['succeeded', 'failed_definite', 'failed_definite'];
        $phone = puxService()->purchase($user, $data, '08012345678', null, (string) Str::uuid());
        $failed = prtBuy($user, prtPlan(RecipientType::Nin, 2), prtNumber());
        $fields = FakeProvider::fixtureFields()->all();
        $store = fn (Purchase $purchase, int $attemptId, mixed $value, ?int $count = null) => (new PurchaseResult)->forceFill([
            'purchase_id' => $purchase->id, 'purchase_attempt_id' => $attemptId, 'encrypted_fields' => $value,
            'field_count' => $count ?? (is_array($value) ? count($value) : 1),
        ])->save();

        expect(prtRefusal(fn () => $store($nin, $ninAttempt->id, $fields)))->toBe("A result is stored only from the purchase's own succeeded delivering attempt.");
        DB::table('purchase_attempts')->whereIn('id', [$ninAttempt->id, $bvnAttempt->id])->update(['status' => 'succeeded']); // as inside a success transaction

        expect(prtRefusal(fn () => $store($nin, $bvnAttempt->id, $fields)))->toBe("A result is stored only from the purchase's own succeeded delivering attempt.")
            ->and(prtRefusal(fn () => $store($phone, $phone->successful_attempt_id, $fields)))->toBe('A result belongs only to a NIN or BVN purchase that is being settled.')
            ->and(prtRefusal(fn () => $store($failed, $failed->attempts()->first()->id, $fields)))->toBe('A result belongs only to a NIN or BVN purchase that is being settled.')
            ->and(prtRefusal(fn () => $store($nin, $ninAttempt->id, $fields, 3)))->toBe('A result needs valid fields and their exact count.')
            ->and(prtRefusal(fn () => $store($nin, $ninAttempt->id, [['key' => 'Fixture', 'label' => 'Fixture', 'value' => 'x']])))
            ->toBe('A result needs valid fields and their exact count.')
            ->and(prtRefusal(fn () => $store($nin, $ninAttempt->id, 'fixture text')))->toBe('A result needs valid fields and their exact count.')
            ->and(PurchaseResult::count())->toBe(0);

        $store($nin, $ninAttempt->id, $fields);
        $result = PurchaseResult::sole();
        expect($result->fields())->toBe($fields)
            ->and(prtRefusal(fn () => $store($nin, $ninAttempt->id, $fields)))->toBe('This purchase already has its result.')
            ->and(fn () => $result->forceFill(['field_count' => 1])->save())->toThrow(LogicException::class, 'Purchase results never change after creation.')
            ->and(fn () => $result->delete())->toThrow(LogicException::class, 'Purchase results are never deleted.')
            ->and(PurchaseResult::count())->toBe(1)
            ->and(PurchaseResult::sole()->field_count)->toBe(2);
    });

    it('refuses success for a NIN/BVN purchase without its result from the delivering attempt', function () {
        FakeProvider::$purchaseScript = ['failed_definite', 'timeout'];
        $purchase = prtBuy(puxCustomer(100_000), prtPlan(RecipientType::Nin, 2), prtNumber());
        [$first, $second] = $purchase->attempts()->orderBy('attempt_number')->get()->all();
        DB::table('purchase_attempts')->where('id', $second->id)->update(['status' => 'succeeded']);
        $succeed = fn () => $purchase->fresh()->forceFill(['status' => PurchaseStatus::Successful, 'successful_attempt_id' => $second->id,
            'cost_kobo' => 10_000, 'margin_kobo' => 5_000, 'next_check_at' => null, 'completed_at' => now()])->save();

        expect(prtRefusal($succeed))->toBe('A NIN or BVN purchase is successful only with its stored result.');
        prtInsertResult($purchase, $first->id); // a result from the attempt that failed
        expect(prtRefusal($succeed))->toBe('A NIN or BVN purchase is successful only with its stored result.')
            ->and($purchase->fresh()->status)->toBe(PurchaseStatus::Pending);
    });
});

describe('database', function () {
    it('has the result table: one row per purchase, tied to an attempt of that same purchase', function () {
        $columns = collect(Schema::getColumns('purchase_results'))->keyBy('name');
        $indexes = collect(Schema::getIndexes('purchase_results'))->keyBy('name');
        $foreign = collect(Schema::getForeignKeys('purchase_results'))
            ->map(fn (array $key) => [$key['columns'], $key['foreign_table'], $key['foreign_columns'], $key['on_delete']])->all();

        expect($columns->keys()->all())->toBe(['id', 'purchase_id', 'purchase_attempt_id', 'encrypted_fields', 'field_count', 'created_at'])
            ->and($columns->map(fn (array $column) => $column['nullable'])->unique()->all())->toBe(['id' => false])
            ->and($columns['encrypted_fields']['type_name'])->toBe('text')
            ->and($columns['created_at']['default'])->toBe('CURRENT_TIMESTAMP')
            ->and(Schema::hasColumn('purchase_results', 'updated_at'))->toBeFalse()
            ->and($indexes['purchase_results_purchase_id_unique'])->toMatchArray(['columns' => ['purchase_id'], 'unique' => true])
            ->and($foreign)->toEqualCanonicalizing([
                [['purchase_id'], 'purchases', ['id'], 'restrict'],
                [['purchase_attempt_id'], 'purchase_attempts', ['id'], 'restrict'],
                [['purchase_attempt_id', 'purchase_id'], 'purchase_attempts', ['id', 'purchase_id'], 'restrict'],
            ]);
    });

    it('refuses a result tied to another purchase\'s attempt, a second result, and deleting what a result belongs to', function () {
        $user = puxCustomer(300_000);
        $nin = prtUnclear(RecipientType::Nin, $user);
        $bvn = prtUnclear(RecipientType::Bvn, $user);
        $bvnAttempt = $bvn->attempts()->sole();

        expect(fn () => prtInsertResult($nin, $bvnAttempt->id))->toThrow(QueryException::class, 'FOREIGN KEY constraint failed');
        prtInsertResult($nin, $nin->attempts()->sole()->id);
        expect(fn () => prtInsertResult($nin, $nin->attempts()->sole()->id))->toThrow(QueryException::class, 'UNIQUE constraint failed: purchase_results.purchase_id')
            ->and(fn () => DB::table('purchase_attempts')->where('purchase_id', $nin->id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY constraint failed')
            ->and(fn () => DB::table('purchases')->where('id', $nin->id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY constraint failed')
            ->and(DB::table('purchase_results')->count())->toBe(1);
    });

    it('refuses to roll back while a result exists, changing nothing', function () {
        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [FakeProvider::fixtureFields()];
        prtBuy(puxCustomer(100_000), prtPlan(RecipientType::Nin), prtNumber());
        $schema = prtSchema();
        $rows = DB::table('purchase_results')->get()->all();
        $migrations = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
        $newest = DB::table('migrations')->where('migration', '>=', PRT_MIGRATION)->count();
        $message = 'Refusing to roll back: NIN/BVN purchases have stored results, which would be lost. Nothing was changed.';

        expect(fn () => (require database_path('migrations/'.PRT_MIGRATION.'.php'))->down())->toThrow(RuntimeException::class, $message)
            ->and(fn () => Artisan::call('migrate:rollback', ['--step' => $newest, '--path' => [database_path('migrations/'.PRT_MIGRATION.'.php')], '--realpath' => true]))
            ->toThrow(RuntimeException::class, $message)
            ->and(prtSchema())->toEqual($schema)
            ->and(DB::table('purchase_results')->get()->all())->toEqual($rows)
            ->and(DB::table('migrations')->orderBy('id')->pluck('migration')->all())->toBe($migrations);
    });

    it('rolls back to exactly the schema before it when no result exists, and migrates again', function () {
        $cp2 = prtSchema();

        $steps = DB::table('migrations')->where('migration', '>', PRT_CP1_LAST)->count();
        Artisan::call('migrate:rollback', ['--step' => $steps]); // CP2 and every later checkpoint, newest first

        expect(Schema::hasTable('purchase_results'))->toBeFalse()
            ->and(DB::table('migrations')->where('migration', PRT_MIGRATION)->exists())->toBeFalse()
            ->and(prtSchema())->toEqual(array_diff_key($cp2, ['purchase_results' => true]));

        Artisan::call('migrate');

        expect(prtSchema())->toEqual($cp2);
    });
});

describe('purchases:verify', function () {
    it('passes successful NIN/BVN purchases with their results, and every other purchase without one', function () {
        $user = puxCustomer(500_000);
        $data = puxPlan('data', 10_000);
        puxRoute($data);
        $buy = function (array $answers, array $results, ?Plan $plan = null) use ($user, $data) {
            FakeProvider::$purchaseScript = $answers;
            FakeProvider::$resultScript = $results;

            return $plan === null ? puxService()->purchase($user, $data, '08012345678', null, (string) Str::uuid()) : prtBuy($user, $plan, prtNumber());
        };
        $buy(['succeeded'], [FakeProvider::fixtureFields()]);
        $buy(['succeeded'], [FakeProvider::fixtureFields()], prtPlan(RecipientType::Nin));
        $buy(['succeeded'], [FakeProvider::fixtureFields()], prtPlan(RecipientType::Bvn));
        $buy(['failed_definite'], [], prtPlan(RecipientType::Nin));
        $buy(['timeout'], [], prtPlan(RecipientType::Bvn));
        $buy(['succeeded'], [], prtPlan(RecipientType::Nin));

        expect(Purchase::orderBy('id')->pluck('status')->map->value->all())->toBe(['successful', 'successful', 'successful', 'failed', 'pending', 'pending'])
            ->and(Artisan::call('purchases:verify'))->toBe(0)
            ->and(trim(Artisan::output()))->toBe('All 6 purchase(s) are consistent with their debits, refunds and attempts, and the purchase totals match.');
    });

    it('reports each result problem by reference only, never printing a value or number', function (string $target, Closure $tamper, string $problem) {
        $user = puxCustomer(500_000);
        $data = puxPlan('data', 10_000);
        puxRoute($data);
        $numbers = [prtNumber(), prtNumber(), prtNumber()];
        $fields = FakeProvider::fixtureFields();
        FakeProvider::$purchaseScript = ['succeeded', 'failed_definite', 'succeeded', 'timeout', 'failed_definite', 'failed_definite'];
        FakeProvider::$resultScript = [null, $fields];
        $purchases = [
            'phone' => puxService()->purchase($user, $data, '08012345678', null, (string) Str::uuid()),
            'successful' => prtBuy($user, prtPlan(RecipientType::Nin, 2), $numbers[0]),
            'pending' => prtBuy($user, prtPlan(RecipientType::Nin), $numbers[1]),
            'failed' => prtBuy($user, prtPlan(RecipientType::Bvn, 2), $numbers[2]),
        ];
        expect(collect($purchases)->map(fn (Purchase $p) => $p->status->value)->values()->all())->toBe(['successful', 'successful', 'pending', 'failed'])
            ->and(Artisan::call('purchases:verify'))->toBe(0);

        $tamper($purchases, $fields->all());

        expect(Artisan::call('purchases:verify'))->toBe(1);
        $output = Artisan::output();
        $purchase = $purchases[$target];
        expect($output)->toContain("Purchase {$purchase->reference} ({$purchase->status->value}): {$problem}")
            ->toContain('1 problem(s) found in 4 purchase(s).')
            ->not->toContain('08012345678');
        foreach ([...$numbers, ...prtValues($fields)] as $secret) {
            expect($output)->not->toContain($secret);
        }
    })->with([
        'successful NIN without its result' => ['successful', fn (array $p) => DB::table('purchase_results')->delete(),
            'a successful NIN purchase without its result.'],
        'result on a phone purchase' => ['phone', fn (array $p) => prtInsertResult($p['phone'], $p['phone']->successful_attempt_id),
            'has a stored result, but only a successful NIN or BVN purchase has one.'],
        'result on a pending purchase' => ['pending', fn (array $p) => prtInsertResult($p['pending'], $p['pending']->attempts()->sole()->id),
            'has a stored result, but only a successful NIN or BVN purchase has one.'],
        'result on a failed purchase' => ['failed', fn (array $p) => prtInsertResult($p['failed'], $p['failed']->attempts()->first()->id),
            'has a stored result, but only a successful NIN or BVN purchase has one.'],
        'result from another attempt' => ['successful', fn (array $p) => DB::table('purchase_results')
            ->update(['purchase_attempt_id' => $p['successful']->attempts()->where('attempt_number', 1)->value('id')]),
            'its result is not from its delivering attempt.'],
        'no field counted' => ['successful', fn () => DB::table('purchase_results')->update(['field_count' => 0]),
            'its result has an invalid field count.'],
        '51 fields counted' => ['successful', fn () => DB::table('purchase_results')->update(['field_count' => 51]),
            'its result has an invalid field count.'],
        'miscounted' => ['successful', fn () => DB::table('purchase_results')->update(['field_count' => 3]),
            'its result cannot be read (app key not in APP_PREVIOUS_KEYS, or a damaged or miscounted value).'],
        'stored in plain text' => ['successful', fn (array $p, array $f) => DB::table('purchase_results')->update(['encrypted_fields' => json_encode($f)]),
            'its result cannot be read (app key not in APP_PREVIOUS_KEYS, or a damaged or miscounted value).'],
        'encrypted with another key' => ['successful', fn (array $p, array $f) => DB::table('purchase_results')->update(['encrypted_fields' => (new Encrypter(
            Encrypter::generateKey(config('app.cipher')), config('app.cipher')))->encryptString(json_encode($f))]),
            'its result cannot be read (app key not in APP_PREVIOUS_KEYS, or a damaged or miscounted value).'],
        'invalid fields' => ['successful', fn () => DB::table('purchase_results')->update(['field_count' => 1,
            'encrypted_fields' => Crypt::encryptString(json_encode([['key' => 'Fixture', 'label' => 'Fixture', 'value' => 'x']]))]),
            'its result cannot be read (app key not in APP_PREVIOUS_KEYS, or a damaged or miscounted value).'],
        'not a list of fields' => ['successful', fn () => DB::table('purchase_results')->update(['field_count' => 1,
            'encrypted_fields' => Crypt::encryptString(json_encode('fixture text'))]),
            'its result cannot be read (app key not in APP_PREVIOUS_KEYS, or a damaged or miscounted value).'],
    ]);
});

describe('security', function () {
    it('never stores, logs, messages or shows a result value or the purchased number in plain text', function (RecipientType $type) {
        $logs = prtRecordLogs();
        $staff = prtStaff();
        $plan = prtPlan($type, 2);
        $user = puxCustomer(300_000);
        $numbers = [prtNumber(), prtNumber(), prtNumber(), prtNumber()];
        $sets = array_map(fn (string $number) => FakeProvider::fixtureFields(3, [2 => 'fixture '.$number]), $numbers); // the third echoes the number

        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [$sets[0]];
        $delivered = prtBuy($user, $plan, $numbers[0]);
        FakeProvider::$purchaseScript = ['failed_definite', 'succeeded'];
        FakeProvider::$resultScript = [$sets[1]];
        $failedOver = prtBuy($user, $plan, $numbers[1]);
        FakeProvider::$purchaseScript = ['succeeded'];
        $missing = prtBuy($user, $plan, $numbers[2]);
        FakeProvider::$purchaseScript = ['timeout'];
        $reviewed = prtBuy($user, $plan, $numbers[3]);
        $this->travel(3)->minutes();
        FakeProvider::$queryScript = ['succeeded', 'unknown'];
        FakeProvider::$resultScript = [$sets[2]];
        Artisan::call('purchases:reconcile');
        $this->travel(25)->hours();
        FakeProvider::$queryScript = ['unknown'];
        Artisan::call('purchases:reconcile');
        expect($reviewed->fresh()->status)->toBe(PurchaseStatus::Review);
        FakeProvider::$queryScript = ['succeeded'];
        FakeProvider::$resultScript = [$sets[3]];
        app(RecheckPurchase::class)->handle($reviewed->fresh(), $staff);
        Artisan::call('purchases:verify');
        $verify = Artisan::output();

        $purchases = [$delivered, $failedOver, $missing, $reviewed];
        expect(collect($purchases)->map(fn (Purchase $p) => $p->fresh()->status->value)->all())->toBe(['successful', 'successful', 'successful', 'successful'])
            ->and(PurchaseResult::count())->toBe(4);
        $pages = $this->actingAs($staff, 'admin')->get('/admin/purchases')->assertOk()->getContent();
        foreach ($purchases as $purchase) {
            $pages .= $this->actingAs($staff, 'admin')->get("/admin/purchases/{$purchase->id}")->assertOk()->assertSee($purchase->reference)->getContent();
            $pages .= $this->actingAs($user, 'web')->get("/purchases/{$purchase->reference}")->assertOk()->getContent();
        }
        $pages .= $this->actingAs($user, 'web')->get('/purchases')->assertOk()->getContent();
        $everything = implode("\n", [prtDatabaseDump(), implode("\n", $logs->getArrayCopy()), $verify, $pages,
            Purchase::with(['result', 'attempts', 'statusChanges'])->get()->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        foreach ($numbers as $number) {
            expect($everything)->not->toContain($number);
        }
        foreach ($sets as $set) {
            foreach (array_slice($set->all(), 0, 2) as $field) {
                expect($everything)->not->toContain($field['value']);
            }
        }
        prtClean();
    })->with('prt identity types');

    it('reads stored results only in the result model and the purchase engine, and no page or route uses them', function () {
        $sources = collect([app_path(), resource_path(), base_path('routes'), config_path()])
            ->flatMap(fn (string $dir) => File::allFiles($dir))
            ->mapWithKeys(fn ($file) => [Str::after($file->getPathname(), base_path().'/') => $file->getContents()]);
        $using = fn (string $needle) => $sources->filter(fn (string $code) => str_contains($code, $needle))->keys()->sort()->values()->all();

        expect($using('encrypted_fields'))->toBe(['app/Models/PurchaseResult.php', 'app/Services/Purchases/PurchaseService.php'])
            ->and($using('->fields()'))->toBe(['app/Models/PurchaseResult.php'])
            ->and($using('canBeRead('))->toBe(['app/Console/Commands/VerifyPurchasesCommand.php', 'app/Models/PurchaseResult.php'])
            ->and($sources->filter(fn ($code, $path) => str_starts_with($path, 'resources/') || str_starts_with($path, 'routes/'))
                ->filter(fn (string $code) => preg_match('/PurchaseResult|purchase_results|encrypted_fields|->result\b|ProviderResultFields/', $code))->keys()->all())
            ->toBe([]);
    });
});
