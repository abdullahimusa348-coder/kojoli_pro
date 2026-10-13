<?php

use App\Actions\Admin\Purchases\RecheckPurchase;
use App\Exceptions\Purchases\PurchaseException;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\PurchaseIdentityRecipient;
use App\Models\PurchaseResult;
use App\Models\PurchaseStatusChange;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderResultFields;
use App\Services\Purchases\PurchaseCatalog;
use App\Services\Settings\SettingsStore;
use App\Services\Wallet\WalletService;
use App\Support\Enums\UserType;
use App\Support\MaintenanceMode;
use App\Support\Purchases\IdentityHasher;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Purchases\RecipientType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;
use Tests\Support\Providers\ResultProbeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 11 CP4: Exam PIN purchases in the engine. An Exam PIN purchase has no
 * recipient of any kind (RecipientType::None): any recipient or face value is
 * refused, its plan must be fixed-price, and the provider is sent an empty
 * recipient with recipientType "none". It succeeds only with the result its
 * provider delivered (at least one value not blank), stored once and
 * encrypted, and is never refunded once it has one. A success without a
 * usable result stays unclear (result_missing). Result values are neutral
 * fixtures generated when the tests run (D1): never a real PIN or serial.
 */

beforeEach(function () {
    puxDrivers();
    FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn', 'exam-pin'];
    ResultProbeProvider::reset();
    Http::preventStrayRequests();
});

/** An available fixed-price plan of the existing exam-pin service (15,000 kobo) with $routes executable routes (cost 10,000 kobo). */
function eptPlan(int $routes = 1, string $driver = 'fake-provider', array $attributes = []): Plan
{
    $service = Service::where('slug', 'exam-pin')->first() ?? Service::factory()->create(['name' => 'Exam PIN', 'slug' => 'exam-pin']);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Fixture exam', 'code' => 'exam-pin-test-'.Str::lower(Str::random(6)),
        'network' => null]);
    $plan = Plan::factory()->create($attributes + ['product_id' => $product->id, 'name' => 'Fixture PIN', 'code' => $product->code.'-p', 'amount_type' => 'fixed']);
    PlanPrice::factory()->create(['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => 15_000]);
    for ($priority = 1; $priority <= $routes; $priority++) {
        puxRoute($plan, $priority, ['cost_type' => 'fixed', 'cost_kobo' => 10_000], $driver);
    }

    return $plan->fresh();
}

function eptBuy(User $user, Plan $plan, ?string $key = null, ?int $confirmedAmountKobo = null): Purchase
{
    return puxService()->purchase($user, $plan, '', null, $key ?? (string) Str::uuid(), $confirmedAmountKobo);
}

/** An Exam PIN purchase whose provider call was unclear (no answer), due for its re-check; its second route is never tried. */
function eptUnclear(?User $user = null, int $routes = 2): Purchase
{
    FakeProvider::$purchaseScript = ['timeout'];
    $purchase = eptBuy($user ?? puxCustomer(100_000), eptPlan($routes));
    $purchase->forceFill(['next_check_at' => now()->subMinute()])->save();

    return $purchase->fresh();
}

/** Neutral fixture fields with the given values (keys fixture_1…). */
function eptFields(string ...$values): ProviderResultFields
{
    return new ProviderResultFields(array_map(fn (string $value, int $i) => ['key' => 'fixture_'.($i + 1), 'label' => 'Fixture '.($i + 1), 'value' => $value],
        $values, array_keys($values)));
}

/** @return list<string> the values of each set */
function eptValues(ProviderResultFields ...$sets): array
{
    return array_merge(...array_map(fn (ProviderResultFields $fields) => array_column($fields->all(), 'value'), $sets));
}

/** The exact refusal message, or null when nothing was refused. */
function eptRefusal(Closure $call): ?string
{
    try {
        $call();
    } catch (PurchaseException $e) {
        return $e->getMessage();
    }

    return null;
}

/** No purchase, no purchase debit, no provider call, and the wallet as it was. */
function eptNothingBought(User $user, int $balanceKobo): void
{
    expect(Purchase::where('user_id', $user->id)->count())->toBe(0)
        ->and(Transaction::where('user_id', $user->id)->where('type', 'purchase')->count())->toBe(0)
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe($balanceKobo)
        ->and(collect(FakeProvider::$calls)->whereInstanceOf(ProviderPurchaseRequest::class)->count())->toBe(0);
}

/** Wallet, ledger and purchase integrity checks both pass. */
function eptClean(): void
{
    expect(Artisan::call('wallet:verify'))->toBe(0)
        ->and(Artisan::call('purchases:verify'))->toBe(0, Artisan::output());
}

/** Every row of every table, as JSON, to scan for plain values. */
function eptDatabaseDump(): string
{
    return collect(DB::select("select name from sqlite_master where type = 'table'"))->pluck('name')
        ->map(fn (string $table) => $table.': '.json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode("\n");
}

/** Records every log line (message and context) written from now on. */
function eptRecordLogs(): ArrayObject
{
    $lines = new ArrayObject;
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $lines->append(
        $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

    return $lines;
}

function eptStaff(): SystemUser
{
    (new RolesAndPermissionsSeeder)->run();
    $staff = SystemUser::factory()->create();
    $staff->assignRole('super-admin');

    return $staff;
}

/** A result row written straight into the table (no model guards), as damaged or tampered data would be. */
function eptInsertResult(Purchase $purchase, int $attemptId, ?array $fields = null): void
{
    $fields ??= FakeProvider::fixtureFields()->all();
    DB::table('purchase_results')->insert(['purchase_id' => $purchase->id, 'purchase_attempt_id' => $attemptId,
        'encrypted_fields' => Crypt::encryptString(json_encode($fields)), 'field_count' => count($fields), 'created_at' => now()]);
}

describe('recipient type', function () {
    it('maps Exam PIN to none: no identity, a required result, and nothing to normalise or mask', function () {
        expect(RecipientType::forServiceSlug('exam-pin'))->toBe(RecipientType::None)
            ->and(RecipientType::None->value)->toBe('none')
            ->and(RecipientType::None->isIdentity())->toBeFalse()
            ->and(RecipientType::None->requiresResult())->toBeTrue()
            ->and(RecipientType::Nin->isIdentity())->toBeTrue()->and(RecipientType::Nin->requiresResult())->toBeTrue()
            ->and(RecipientType::Bvn->isIdentity())->toBeTrue()->and(RecipientType::Bvn->requiresResult())->toBeTrue()
            ->and(RecipientType::Phone->isIdentity())->toBeFalse()->and(RecipientType::Phone->requiresResult())->toBeFalse()
            ->and(RecipientType::forServiceSlug('smile-data'))->toBeNull()
            ->and(fn () => RecipientType::None->normalize('12345678901'))->toThrow(LogicException::class, 'This purchase has no recipient.')
            ->and(fn () => RecipientType::None->mask('12345678901'))->toThrow(LogicException::class, 'This purchase has no recipient.')
            ->and(fn () => RecipientType::None->invalidMessage())->toThrow(LogicException::class, 'This purchase has no recipient.')
            ->and(fn () => RecipientType::Phone->normalize('08012345678'))->toThrow(LogicException::class, 'Phone recipients use NigerianPhone.');
    });
});

describe('creation', function () {
    it('creates a pending Exam PIN purchase with no recipient of any kind, one debit and no identity recipient', function () {
        $user = puxCustomer(100_000);
        $plan = eptPlan();
        FakeProvider::$purchaseScript = ['unknown'];

        $purchase = eptBuy($user, $plan);

        $row = DB::table('purchases')->where('id', $purchase->id)->sole();
        expect($purchase->status)->toBe(PurchaseStatus::Pending)
            ->and($purchase->recipient_type)->toBe(RecipientType::None)
            ->and($row->recipient_type)->toBe('none')
            ->and($row->recipient)->toBeNull()
            ->and($row->request_fingerprint)->toBeNull()
            ->and($row->face_value_kobo)->toBeNull()
            ->and($row->network)->toBeNull()
            ->and((int) $row->amount_kobo)->toBe(15_000)
            ->and($row->amount_type)->toBe('fixed')
            ->and($row->service_name)->toBe('Exam PIN')
            ->and($purchase->displayRecipient())->toBe('—')
            ->and(PurchaseIdentityRecipient::count())->toBe(0)
            ->and(PurchaseResult::count())->toBe(0)
            ->and(Transaction::where('user_id', $user->id)->where('type', 'purchase')->count())->toBe(1)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
        eptClean();
    });

    it('sends the provider an empty recipient with recipientType none, and nothing about the customer', function () {
        $plan = eptPlan();
        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [FakeProvider::fixtureFields()];

        $purchase = eptBuy(puxCustomer(100_000), $plan);

        $request = collect(FakeProvider::$calls)->sole();
        expect($request)->toBeInstanceOf(ProviderPurchaseRequest::class)
            ->and($request->recipient)->toBe('')
            ->and($request->recipientType)->toBe('none')
            ->and($request->serviceSlug)->toBe('exam-pin')
            ->and($request->network)->toBeNull()
            ->and($request->providerPlanCode)->toBe('CODE1')
            ->and($request->amountKobo)->toBe(15_000)
            ->and($request->faceValueKobo)->toBeNull()
            ->and($request->requestReference)->toBe($purchase->attempts->sole()->request_reference)
            ->and(print_r($request, true))->toContain('[redacted]');
    });

    it('refuses any recipient, with nothing bought', function (Closure $recipient) {
        $user = puxCustomer(100_000);
        $plan = eptPlan();

        expect(eptRefusal(fn () => puxService()->purchase($user, $plan, $recipient(), null, (string) Str::uuid(), 15_000)))
            ->toBe('This service takes no recipient.');
        eptNothingBought($user, 100_000);
    })->with([
        'a phone number' => [fn () => '08012345678'],
        'an 11-digit number' => [fn () => (string) random_int(10_000_000_000, 99_999_999_999)],
        'an email address' => [fn () => 'fixture-'.Str::lower(Str::random(8)).'@example.test'],
        'a candidate-like identifier' => [fn () => 'FIXTURE-'.Str::upper(Str::random(10))],
        'a single space' => [fn () => ' '],
    ]);

    it('refuses a face value or a variable plan, with nothing bought', function () {
        $user = puxCustomer(100_000);
        $plan = eptPlan();
        $variable = eptPlan(1, 'fake-provider', ['amount_type' => 'variable', 'min_amount_kobo' => 5_000, 'max_amount_kobo' => 50_000]);
        PlanPrice::where('plan_id', $variable->id)->update(['price_kobo' => null, 'discount_bps' => 0, 'fee_kobo' => 0]);

        expect(eptRefusal(fn () => puxService()->purchase($user, $plan, '', 15_000, (string) Str::uuid())))->toBe('This plan is sold at a fixed price only.')
            ->and(eptRefusal(fn () => eptBuy($user, $variable->fresh())))->toBe('This plan is sold at a fixed price only.')
            ->and(eptRefusal(fn () => puxService()->purchase($user, $variable->fresh(), '', 10_000, (string) Str::uuid())))
            ->toBe('This plan is sold at a fixed price only.');
        eptNothingBought($user, 100_000);
    });

    it('refuses a disabled plan, a changed price and a wallet that cannot pay, with nothing bought', function (Closure $setUp, string $message) {
        $plan = eptPlan();
        $user = puxCustomer(100_000);
        [$plan, $user, $confirmed] = $setUp($plan, $user);

        expect(eptRefusal(fn () => eptBuy($user, $plan, null, $confirmed)))->toStartWith($message);
        eptNothingBought($user, Wallet::where('user_id', $user->id)->sole()->balance_kobo);
    })->with([
        'disabled plan' => [function (Plan $plan, User $user) {
            $plan->forceFill(['is_active' => false])->save();

            return [$plan->fresh(), $user, 15_000];
        }, 'Plan unavailable'],
        'changed price' => [fn (Plan $plan, User $user) => [$plan, $user, 14_000], 'The price has changed. Please review the new price and confirm again.'],
        'insufficient balance' => [fn (Plan $plan) => [$plan, puxCustomer(10_000), 15_000], 'The wallet balance is not enough for this debit.'],
    ]);

    it('offers and sells nothing while no provider can run it (providers.drivers = [])', function () {
        $plan = eptPlan();
        $user = puxCustomer(100_000);
        config(['providers.drivers' => []]);

        expect(app(PurchaseCatalog::class)->plans($user, 'exam-pin'))->toBeEmpty()
            ->and(eptRefusal(fn () => eptBuy($user, $plan, null, 15_000)))->toBe('This plan is not available right now.');
        eptNothingBought($user, 100_000);
    });

    it('refuses new Exam PIN purchases in maintenance mode, while a repeated request still gets its purchase', function () {
        $plan = eptPlan();
        $user = puxCustomer(100_000);
        FakeProvider::$purchaseScript = ['timeout'];
        $key = (string) Str::uuid();
        $bought = eptBuy($user, $plan, $key, 15_000);

        app(SettingsStore::class)->set(MaintenanceMode::SETTING, true);

        expect(eptRefusal(fn () => eptBuy($user, $plan, null, 15_000)))->toBe(MaintenanceMode::MESSAGE)
            ->and(eptBuy($user, $plan, $key, 15_000)->id)->toBe($bought->id)
            ->and(Purchase::count())->toBe(1)
            ->and(Transaction::where('user_id', $user->id)->where('type', 'purchase')->count())->toBe(1);
    });
});

describe('idempotency', function () {
    it('returns the same purchase for a repeated request, with one debit and one provider call', function () {
        $plan = eptPlan();
        $user = puxCustomer(100_000);
        $key = (string) Str::uuid();
        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [FakeProvider::fixtureFields()];

        $first = eptBuy($user, $plan, $key, 15_000);
        $again = eptBuy($user, $plan, $key, 15_000);
        $unconfirmed = eptBuy($user, $plan, $key); // the engine called without a confirmed amount

        expect($again->id)->toBe($first->id)
            ->and($unconfirmed->id)->toBe($first->id)
            ->and(Purchase::count())->toBe(1)
            ->and(PurchaseResult::count())->toBe(1)
            ->and(FakeProvider::$calls)->toHaveCount(1)
            ->and(Transaction::where('user_id', $user->id)->where('type', 'purchase')->count())->toBe(1)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
        eptClean();
    });

    it('refuses the same token for another plan, another amount or another service, buying nothing more', function () {
        $plan = eptPlan();
        $other = eptPlan();
        $data = puxPlan('data', 10_000);
        puxRoute($data);
        $nin = puxPlan('nin', 15_000);
        puxRoute($nin);
        $user = puxCustomer(300_000);
        $examKey = (string) Str::uuid();
        $dataKey = (string) Str::uuid();
        FakeProvider::$purchaseScript = ['timeout', 'timeout'];
        eptBuy($user, $plan, $examKey, 15_000);
        puxService()->purchase($user, $data, '08012345678', null, $dataKey);
        $message = 'This request was already used for a different purchase. Please start again.';

        expect(eptRefusal(fn () => eptBuy($user, $other, $examKey, 15_000)))->toBe($message)
            ->and(eptRefusal(fn () => eptBuy($user, $plan, $examKey, 14_000)))->toBe($message)
            ->and(eptRefusal(fn () => eptBuy($user, $plan, $dataKey, 15_000)))->toBe($message)
            ->and(eptRefusal(fn () => puxService()->purchase($user, $data, '08012345678', null, $examKey)))->toBe($message)
            ->and(eptRefusal(fn () => puxService()->purchase($user, $nin, (string) random_int(10_000_000_000, 99_999_999_999), null, $examKey, null, true)))
            ->toBe($message)
            ->and(Purchase::count())->toBe(2)
            ->and(PurchaseIdentityRecipient::count())->toBe(0)
            ->and(Transaction::where('user_id', $user->id)->where('type', 'purchase')->count())->toBe(2)
            ->and(FakeProvider::$calls)->toHaveCount(2);
    });

    it('compares a repeated key by the purchase\'s own plan, storing no fingerprint', function () {
        $plan = eptPlan();
        $other = eptPlan();
        FakeProvider::$purchaseScript = ['timeout'];
        $purchase = eptBuy(puxCustomer(100_000), $plan);

        expect(Purchase::recipientlessFingerprint($plan->id))->toBe(hash('sha256', $plan->id.'|none|-'))
            ->and(Purchase::recipientlessFingerprint($plan->id))->not->toBe(Purchase::recipientlessFingerprint($other->id))
            ->and(DB::table('purchases')->where('id', $purchase->id)->value('request_fingerprint'))->toBeNull()
            ->and(eptDatabaseDump())->not->toContain(Purchase::recipientlessFingerprint($plan->id));
    });
});

describe('results', function () {
    it('succeeds only with the delivered result, stored once, encrypted and as delivered', function () {
        $this->freezeTime();
        $user = puxCustomer(100_000);
        $fields = FakeProvider::fixtureFields(2);
        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [$fields];

        $purchase = eptBuy($user, eptPlan(), null, 15_000);

        $row = DB::table('purchase_results')->sole();
        $attempt = $purchase->attempts->sole();
        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and($purchase->successful_attempt_id)->toBe($attempt->id)
            ->and($purchase->cost_kobo)->toBe(10_000)
            ->and($purchase->margin_kobo)->toBe(5_000)
            ->and((int) $row->purchase_attempt_id)->toBe($attempt->id)
            ->and((int) $row->field_count)->toBe(2)
            ->and(json_decode(Crypt::decryptString($row->encrypted_fields), true))->toBe($fields->all())
            ->and($purchase->result->fields())->toBe($fields->all())
            ->and($attempt->provider_reference)->toBe('FP-'.$attempt->request_reference)
            ->and(PurchaseStatusChange::where('purchase_id', $purchase->id)->where('new_status', 'successful')->count())->toBe(1)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
        foreach (eptValues($fields) as $value) {
            expect($row->encrypted_fields)->not->toContain($value);
        }
        eptClean();
    });

    it('treats a success without the result as unclear (result_missing), never refunding or failing over, until a re-check delivers it', function () {
        $user = puxCustomer(100_000);
        FakeProvider::$purchaseScript = ['succeeded']; // no result fields

        $purchase = eptBuy($user, eptPlan(2));

        $attempt = $purchase->attempts->sole();
        expect($purchase->status)->toBe(PurchaseStatus::Pending)
            ->and($attempt->status)->toBe(PurchaseAttemptStatus::Unknown)
            ->and($attempt->error_code)->toBe('result_missing')
            ->and($attempt->error_message)->toBe('The provider reported success without the result.')
            ->and($purchase->refund_transaction_id)->toBeNull()
            ->and(PurchaseResult::count())->toBe(0)
            ->and(FakeProvider::$calls)->toHaveCount(1);
        eptClean();

        puxService()->execute($purchase->fresh()); // an unclear attempt is only ever re-checked
        expect(FakeProvider::$calls)->toHaveCount(1)->and($purchase->attempts()->count())->toBe(1);

        $this->travel(3)->minutes();
        $fields = FakeProvider::fixtureFields();
        FakeProvider::$queryScript = ['succeeded'];
        FakeProvider::$resultScript = [$fields];
        Artisan::call('purchases:reconcile');

        $purchase->refresh();
        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and($purchase->result->purchase_attempt_id)->toBe($attempt->id)
            ->and($purchase->result->fields())->toBe($fields->all())
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(0)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
        eptClean();
    });

    it('treats a result whose values are all blank as missing, until a usable result is delivered', function (array $blank) {
        $user = puxCustomer(100_000);
        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [eptFields(...$blank)];

        $purchase = eptBuy($user, eptPlan(2));

        $attempt = $purchase->attempts->sole();
        expect($purchase->status)->toBe(PurchaseStatus::Pending)
            ->and($attempt->status)->toBe(PurchaseAttemptStatus::Unknown)
            ->and($attempt->error_code)->toBe('result_missing')
            ->and(PurchaseResult::count())->toBe(0)
            ->and($purchase->refund_transaction_id)->toBeNull()
            ->and(FakeProvider::$calls)->toHaveCount(1);

        $this->travel(3)->minutes();
        FakeProvider::$queryScript = ['succeeded', 'succeeded'];
        FakeProvider::$resultScript = [eptFields(...$blank)];
        Artisan::call('purchases:reconcile');
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Pending)->and(PurchaseResult::count())->toBe(0);

        $usable = eptFields('', 'FIXTURE-'.Str::upper(Str::random(12)));
        FakeProvider::$resultScript = [$usable];
        app(RecheckPurchase::class)->handle($purchase->fresh(), eptStaff());

        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Successful)
            ->and($purchase->fresh()->result->fields())->toBe($usable->all())
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
        eptClean();
    })->with([
        'an empty value' => [['']],
        'spaces' => [['   ']],
        'a no-break space' => [["\u{00A0}"]],
        'a zero-width space' => [["\u{200B}"]],
        'several blank values' => [['', ' ', "\u{2003}\u{FEFF}"]],
    ]);

    it('keeps it unclear while re-checks report no usable result, moves it to review, and never refunds it', function () {
        $staff = eptStaff();
        $purchase = eptUnclear();
        FakeProvider::$queryScript = ['succeeded'];
        Artisan::call('purchases:reconcile');
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Pending)
            ->and($purchase->attempts()->sole()->error_code)->toBe('result_missing');

        $this->travel(25)->hours();
        FakeProvider::$queryScript = ['succeeded'];
        FakeProvider::$resultScript = [eptFields(' ')];
        Artisan::call('purchases:reconcile');
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Review)
            ->and(PurchaseResult::count())->toBe(0);

        $fields = FakeProvider::fixtureFields();
        FakeProvider::$queryScript = ['succeeded'];
        FakeProvider::$resultScript = [$fields];
        app(RecheckPurchase::class)->handle($purchase->fresh(), $staff);

        $purchase->refresh();
        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and($purchase->result->fields())->toBe($fields->all())
            ->and(PurchaseStatusChange::where('purchase_id', $purchase->id)->where('new_status', 'successful')->sole()->changed_by)->toBe($staff->id)
            ->and(PurchaseAttempt::where('purchase_id', $purchase->id)->count())->toBe(1)
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(0);
        eptClean();
    });

    it('fails over a definite failure to deliver the result, and refunds exactly once when every route fails definitely', function () {
        $user = puxCustomer(100_000);
        $fields = FakeProvider::fixtureFields();
        FakeProvider::$purchaseScript = ['failed_definite', 'succeeded', 'failed_definite', 'failed_definite'];
        FakeProvider::$resultScript = [$fields];

        $delivered = eptBuy($user, eptPlan(2));
        $failed = eptBuy($user, eptPlan(2));

        [$first, $second] = $delivered->attempts()->orderBy('attempt_number')->get()->all();
        expect($delivered->status)->toBe(PurchaseStatus::Successful)
            ->and($first->status)->toBe(PurchaseAttemptStatus::FailedDefinite)
            ->and($second->status)->toBe(PurchaseAttemptStatus::Succeeded)
            ->and($delivered->result->purchase_attempt_id)->toBe($second->id)
            ->and($delivered->result->fields())->toBe($fields->all())
            ->and($failed->status)->toBe(PurchaseStatus::Failed)
            ->and($failed->attempts()->count())->toBe(2)
            ->and($failed->result)->toBeNull()
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$failed->reference)->count())->toBe(1)
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$delivered->reference)->count())->toBe(0)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
        eptClean();
    });

    it('never fails over or refunds after an unclear outcome; a re-check settles it either way', function () {
        $staff = eptStaff();
        $user = puxCustomer(100_000);
        $declined = eptUnclear($user);
        $delivered = eptUnclear($user);
        puxService()->execute($declined->fresh());
        expect(FakeProvider::$calls)->toHaveCount(2)
            ->and($declined->attempts()->count())->toBe(1);

        FakeProvider::$queryScript = ['failed_definite'];
        app(RecheckPurchase::class)->handle($declined->fresh(), $staff);
        $fields = FakeProvider::fixtureFields();
        FakeProvider::$queryScript = ['succeeded'];
        FakeProvider::$resultScript = [$fields];
        app(RecheckPurchase::class)->handle($delivered->fresh(), $staff);

        expect($declined->fresh()->status)->toBe(PurchaseStatus::Failed)
            ->and($declined->attempts()->count())->toBe(1) // no failover after an unclear attempt
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$declined->reference)->count())->toBe(1)
            ->and($delivered->fresh()->status)->toBe(PurchaseStatus::Successful)
            ->and($delivered->fresh()->result->fields())->toBe($fields->all())
            ->and(PurchaseResult::count())->toBe(1)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
        eptClean();
    });

    it('never refunds an Exam PIN purchase that has a result, whatever the provider later says', function () {
        $staff = eptStaff();
        $user = puxCustomer(100_000);
        $purchase = eptUnclear($user);
        $attempt = $purchase->attempts()->sole();
        eptInsertResult($purchase, $attempt->id); // a result beside an unclear attempt: not possible through the engine

        FakeProvider::$queryScript = ['failed_definite'];
        expect(puxService()->reconcile()['errors'])->toBe(1);
        FakeProvider::$queryScript = ['failed_definite'];
        expect(fn () => app(RecheckPurchase::class)->handle($purchase->fresh(), $staff))
            ->toThrow(PurchaseException::class, 'A purchase whose result was delivered is never refunded.');

        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Pending)
            ->and($purchase->fresh()->refund_transaction_id)->toBeNull()
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(0)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
    });

    it('stores a result mapped by an HTTP adapter that sends no recipient, and makes an invalid one unclear without storing it', function () {
        config(['providers.drivers' => ['fake-provider' => FakeProvider::class, 'result_probe' => ResultProbeProvider::class]]);
        ResultProbeProvider::$services = ['exam-pin'];
        $plan = eptPlan(2, 'result_probe');
        $user = puxCustomer(100_000);
        $logs = eptRecordLogs();
        $pin = 'FIXTURE-'.Str::upper(Str::random(16));
        $invalid = 'FIXTURE-'.Str::upper(Str::random(16));
        Http::fake(['https://api.result-probe.test/*' => Http::sequence()
            ->push(['status' => 'delivered', 'id' => 'RP-1', 'items' => [['name' => 'Fixture 1', 'text' => $pin]]])
            ->push(['status' => 'delivered', 'id' => 'RP-2', 'items' => [['name' => 'Fixture 1', 'text' => $invalid."\x07"]]])]);

        $delivered = eptBuy($user, $plan);
        $unclear = eptBuy($user, $plan);

        Http::assertSent(fn (HttpRequest $request) => $request->data() === ['reference' => $delivered->attempts->sole()->request_reference, 'service' => 'exam-pin']);
        Http::assertNotSent(fn (HttpRequest $request) => array_key_exists('number', $request->data()));
        expect($delivered->status)->toBe(PurchaseStatus::Successful)
            ->and($delivered->result->fields())->toBe([['key' => 'item_1', 'label' => 'Fixture 1', 'value' => $pin]])
            ->and($delivered->attempts->sole()->provider_reference)->toBe('RP-1')
            ->and($unclear->status)->toBe(PurchaseStatus::Pending)
            ->and($unclear->attempts->sole()->error_code)->toBe('adapter_error')
            ->and($unclear->refund_transaction_id)->toBeNull()
            ->and(PurchaseResult::count())->toBe(1)
            ->and($logs->getArrayCopy())->toBe(['Provider adapter error {"driver":"result_probe","call":"purchase","exception":"InvalidArgumentException"}'])
            ->and(eptDatabaseDump())->not->toContain($pin)->not->toContain($invalid);
        eptClean();
    });

    it('keeps no provider reference that repeats a delivered value, on the purchase call or a re-check', function () {
        config(['providers.drivers' => ['fake-provider' => FakeProvider::class, 'result_probe' => ResultProbeProvider::class]]);
        ResultProbeProvider::$services = ['exam-pin'];
        ResultProbeProvider::$itemInReference = true;
        $plan = eptPlan(1, 'result_probe');
        $user = puxCustomer(100_000);
        $logs = eptRecordLogs();
        $pin = 'FIXTURE-'.Str::upper(Str::random(16));
        $later = 'FIXTURE-'.Str::upper(Str::random(16));
        Http::fake(['https://api.result-probe.test/*' => Http::sequence()
            ->push(['status' => 'delivered', 'id' => 'RP-1', 'items' => [['name' => 'Fixture 1', 'text' => " {$pin} "]]])
            ->push('Gateway timeout', 504)
            ->push(['status' => 'delivered', 'id' => 'RP-3', 'items' => [['name' => 'Fixture 1', 'text' => $later]]])]);

        $direct = eptBuy($user, $plan);
        $rechecked = eptBuy($user, $plan);
        expect($rechecked->status)->toBe(PurchaseStatus::Pending)
            ->and($rechecked->attempts->sole()->provider_reference)->toBeNull();
        app(RecheckPurchase::class)->handle($rechecked->fresh(), eptStaff());

        expect($direct->status)->toBe(PurchaseStatus::Successful)
            ->and($direct->attempts->sole()->provider_reference)->toBeNull()
            ->and($direct->result->fields()[0]['value'])->toBe(" {$pin} ")
            ->and($rechecked->fresh()->status)->toBe(PurchaseStatus::Successful)
            ->and($rechecked->attempts()->sole()->provider_reference)->toBeNull()
            ->and(array_values(array_filter($logs->getArrayCopy(), fn (string $line) => str_starts_with($line, 'Provider reference'))))->toBe([
                'Provider reference not stored: it repeats a delivered result value {"purchase":"'.$direct->reference.'"}',
                'Provider reference not stored: it repeats a delivered result value {"purchase":"'.$rechecked->reference.'"}',
            ])
            ->and(eptDatabaseDump().implode("\n", $logs->getArrayCopy()))->not->toContain($pin)->not->toContain($later);
        eptClean();
    });
});

describe('model guards and integrity', function () {
    it('lets an Exam PIN purchase store no recipient, never get an identity recipient, and succeed only with a non-blank result', function () {
        $plan = eptPlan();
        $user = puxCustomer(100_000);
        $wallet = app(WalletService::class)->walletFor($user);
        $direct = fn (array $attributes) => (new Purchase)->forceFill($attributes + [
            'reference' => WalletService::reference('PUR'), 'user_id' => $user->id, 'wallet_id' => $wallet->id, 'plan_id' => $plan->id,
            'service_id' => $plan->product->service_id, 'service_name' => $plan->product->service->name, 'product_name' => $plan->product->name,
            'plan_name' => $plan->name, 'network' => null, 'user_type' => $user->user_type, 'amount_type' => $plan->amount_type,
            'amount_kobo' => 15_000, 'status' => PurchaseStatus::Pending, 'idempotency_key' => (string) Str::uuid(), 'recipient_type' => RecipientType::None,
        ])->save();

        expect(eptRefusal(fn () => $direct(['recipient' => '08012345678'])))->toBe('An Exam PIN purchase stores no recipient or fingerprint.')
            ->and(eptRefusal(fn () => $direct(['request_fingerprint' => Purchase::recipientlessFingerprint($plan->id)])))
            ->toBe('An Exam PIN purchase stores no recipient or fingerprint.')
            ->and(eptRefusal(fn () => $direct(['recipient_type' => RecipientType::Phone, 'recipient' => '08012345678',
                'request_fingerprint' => Purchase::fingerprint($plan->id, '08012345678', null)])))
            ->toBe("The recipient type must be the one the purchase's service uses.")
            ->and(Purchase::count())->toBe(0);

        $purchase = eptUnclear($user);
        $attempt = $purchase->attempts()->sole();
        $number = (string) random_int(10_000_000_000, 99_999_999_999);
        expect(eptRefusal(fn () => (new PurchaseIdentityRecipient)->forceFill(['purchase_id' => $purchase->id, 'encrypted_value' => $number,
            'masked_value' => RecipientType::Nin->mask($number), 'lookup_hash' => IdentityHasher::lookupHash(RecipientType::Nin, $number),
            'keyed_fingerprint' => IdentityHasher::keyedFingerprint($plan->id, RecipientType::Nin, $number, null), 'consented_at' => now()])->save()))
            ->toBe('An identity recipient belongs only to a NIN or BVN purchase that is being created.');

        DB::table('purchase_attempts')->where('id', $attempt->id)->update(['status' => 'succeeded']); // as inside a success transaction
        $store = fn (array $fields) => (new PurchaseResult)->forceFill(['purchase_id' => $purchase->id, 'purchase_attempt_id' => $attempt->id,
            'encrypted_fields' => $fields, 'field_count' => count($fields)])->save();
        $succeed = fn () => $purchase->fresh()->forceFill(['status' => PurchaseStatus::Successful, 'successful_attempt_id' => $attempt->id,
            'cost_kobo' => 10_000, 'margin_kobo' => 5_000, 'completed_at' => now()])->save();

        expect(eptRefusal($succeed))->toBe('An Exam PIN purchase is successful only with its stored result.')
            ->and(eptRefusal(fn () => $store(eptFields(' ', "\u{200B}")->all())))->toBe('An Exam PIN result needs at least one value that is not blank.')
            ->and(PurchaseResult::count())->toBe(0)
            ->and(eptRefusal(fn () => $store(eptFields('', 'FIXTURE-'.Str::upper(Str::random(8)))->all())))->toBeNull()
            ->and(eptRefusal($succeed))->toBeNull()
            ->and($purchase->fresh()->status)->toBe(PurchaseStatus::Successful);
    });

    it('lets purchases:verify accept Exam PIN purchases and name each problem without printing a value', function (Closure $tamper, string $problem) {
        $user = puxCustomer(300_000);
        $plan = eptPlan(2);
        $fields = FakeProvider::fixtureFields();
        FakeProvider::$purchaseScript = ['failed_definite', 'succeeded', 'failed_definite', 'failed_definite', 'timeout'];
        FakeProvider::$resultScript = [$fields];
        $purchases = ['successful' => eptBuy($user, $plan), 'failed' => eptBuy($user, $plan), 'pending' => eptBuy($user, $plan)];
        eptClean();

        $tamper($purchases);

        expect(Artisan::call('purchases:verify'))->toBe(1);
        $output = Artisan::output();
        expect($output)->toContain($problem)
            ->toContain('1 problem(s) found in 3 purchase(s).');
        foreach (eptValues($fields) as $value) {
            expect($output)->not->toContain($value);
        }
    })->with([
        'successful without its result' => [fn (array $p) => DB::table('purchase_results')->delete(),
            'a successful Exam PIN purchase without its result.'],
        'a stored recipient' => [fn (array $p) => DB::table('purchases')->where('id', $p['pending']->id)->update(['recipient' => '08012345678']),
            'an Exam PIN purchase that stores a recipient or fingerprint.'],
        'a stored fingerprint' => [fn (array $p) => DB::table('purchases')->where('id', $p['pending']->id)->update(['request_fingerprint' => str_repeat('a', 64)]),
            'an Exam PIN purchase that stores a recipient or fingerprint.'],
        'an identity recipient' => [fn (array $p) => DB::table('purchase_identity_recipients')->insert(['purchase_id' => $p['pending']->id,
            'encrypted_value' => Crypt::encryptString('12345678901'), 'masked_value' => '•••••••8901', 'lookup_hash' => str_repeat('b', 64),
            'keyed_fingerprint' => str_repeat('c', 64), 'consented_at' => now(), 'created_at' => now()]),
            'an Exam PIN purchase with an identity recipient.'],
        'a result on a failed purchase' => [fn (array $p) => eptInsertResult($p['failed'], $p['failed']->attempts()->first()->id),
            'has a stored result, but only a successful NIN, BVN or Exam PIN purchase has one.'],
        'a result from another attempt' => [fn (array $p) => DB::table('purchase_results')
            ->update(['purchase_attempt_id' => $p['successful']->attempts()->where('attempt_number', 1)->value('id')]),
            'its result is not from its delivering attempt.'],
        'an unreadable result' => [fn () => DB::table('purchase_results')->update(['encrypted_fields' => 'damaged']),
            'its result cannot be read (app key not in APP_PREVIOUS_KEYS, or a damaged or miscounted value).'],
    ]);
});

describe('security', function () {
    it('never writes the PIN or serial anywhere but the encrypted result', function () {
        $logs = eptRecordLogs();
        $user = puxCustomer(100_000);
        $plan = eptPlan(2);
        $fields = FakeProvider::fixtureFields(2);
        FakeProvider::$purchaseScript = ['failed_definite', 'succeeded'];
        FakeProvider::$resultScript = [$fields];

        $purchase = eptBuy($user, $plan, null, 15_000);
        $refused = null;
        try {
            (new PurchaseResult)->forceFill(['purchase_id' => $purchase->id, 'purchase_attempt_id' => $purchase->successful_attempt_id,
                'encrypted_fields' => $fields->all(), 'field_count' => 2])->save();
        } catch (PurchaseException $e) {
            $refused = $e->getMessage();
        }

        $loaded = Purchase::with(['result', 'attempts', 'statusChanges', 'debitTransaction'])->findOrFail($purchase->id);
        $everything = implode("\n", [eptDatabaseDump(), implode("\n", $logs->getArrayCopy()), $loaded->toJson(), print_r($loaded->toArray(), true),
            json_encode(Transaction::where('user_id', $user->id)->get()), (string) $refused, print_r(collect(FakeProvider::$calls)->all(), true)]);
        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and($refused)->toBe('A result belongs only to a NIN, BVN or Exam PIN purchase that is being settled.')
            ->and(array_keys($loaded->toArray()['result']))->toEqualCanonicalizing(['id', 'purchase_id', 'purchase_attempt_id', 'field_count', 'created_at']);
        foreach (eptValues($fields) as $value) {
            expect($everything)->not->toContain($value);
        }
    });

    it('keeps Data, Airtime, NIN and BVN purchases exactly as before beside Exam PIN', function () {
        $user = puxCustomer(300_000);
        $data = puxPlan('data', 10_000);
        puxRoute($data);
        $nin = puxPlan('nin', 15_000);
        puxRoute($nin);
        $number = (string) random_int(10_000_000_000, 99_999_999_999);
        FakeProvider::$purchaseScript = ['succeeded', 'succeeded', 'succeeded'];
        FakeProvider::$resultScript = [null, FakeProvider::fixtureFields(), FakeProvider::fixtureFields()];

        $phone = puxService()->purchase($user, $data, '08012345678', null, (string) Str::uuid());
        $identity = puxService()->purchase($user, $nin, $number, null, (string) Str::uuid(), null, true);
        $exam = eptBuy($user, eptPlan());

        $sent = collect(FakeProvider::$calls)->whereInstanceOf(ProviderPurchaseRequest::class)->values();
        expect($sent->pluck('recipientType')->all())->toBe(['phone', 'nin', 'none'])
            ->and($sent->pluck('recipient')->all())->toBe(['08012345678', $number, ''])
            ->and($phone->recipient)->toBe('08012345678')
            ->and($phone->request_fingerprint)->toBe(Purchase::fingerprint($data->id, '08012345678', null))
            ->and($phone->displayRecipient())->toBe('08012345678')
            ->and($phone->result)->toBeNull()
            ->and($identity->identityRecipient->masked_value)->toBe('•••••••'.substr($number, -4))
            ->and($identity->fresh()->displayRecipient())->toBe('•••••••'.substr($number, -4))
            ->and($identity->result)->not->toBeNull()
            ->and($exam->displayRecipient())->toBe('—')
            ->and(collect([$phone, $identity, $exam])->map(fn (Purchase $p) => $p->fresh()->status)->unique()->all())->toBe([PurchaseStatus::Successful])
            ->and(PurchaseIdentityRecipient::count())->toBe(1)
            ->and(PurchaseResult::count())->toBe(2);
        eptClean();
    });
});
