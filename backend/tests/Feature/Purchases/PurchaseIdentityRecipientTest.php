<?php

use App\Actions\Admin\Purchases\RecheckPurchase;
use App\Exceptions\Purchases\PurchaseException;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\PurchaseIdentityRecipient;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Settings\SettingsStore;
use App\Services\Wallet\WalletService;
use App\Support\Enums\UserType;
use App\Support\MaintenanceMode;
use App\Support\Purchases\IdentityHasher;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseSource;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Purchases\RecipientType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 11 CP1: typed purchase recipients. A NIN/BVN purchase keeps its
 * number only in purchase_identity_recipients (encrypted, masked, keyed
 * hashes) and stores no phone recipient or phone fingerprint; phone purchases
 * keep the exact Phase 10 behaviour. Test numbers are generated when the
 * tests run, never committed. The test-only FakeProvider returns outcomes
 * only: no NIN/BVN results exist anywhere.
 */

beforeEach(function () {
    puxDrivers();
    FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn', 'exam-pin', 'smile-data'];
    Http::preventStrayRequests();
});

dataset('pir identity types', ['NIN' => [RecipientType::Nin], 'BVN' => [RecipientType::Bvn]]);

/** A random 11-digit number, generated when the test runs (starts 1-9, so it never looks like a phone). */
function pirNumber(): string
{
    return (string) random_int(10_000_000_000, 99_999_999_999);
}

/** An available NIN or BVN plan (fixed price unless $variable) with $routes executable FakeProvider routes. */
function pirPlan(RecipientType $type, int $priceKobo = 15_000, int $routes = 1, bool $variable = false): Plan
{
    $service = Service::where('slug', $type->value)->first() ?? Service::factory()->create(['name' => $type->label(), 'slug' => $type->value]);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Test product', 'code' => $type->value.'-test-'.Str::lower(Str::random(6))]);
    $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Test plan', 'code' => $product->code.'-p',
        'amount_type' => $variable ? 'variable' : 'fixed', 'min_amount_kobo' => $variable ? 5_000 : null, 'max_amount_kobo' => $variable ? 5_000_000 : null]);
    PlanPrice::factory()->create($variable
        ? ['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => null, 'discount_bps' => 0, 'fee_kobo' => 0]
        : ['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => $priceKobo]);
    for ($priority = 1; $priority <= $routes; $priority++) {
        puxRoute($plan, $priority, ['cost_type' => 'fixed', 'cost_kobo' => 10_000]);
    }

    return $plan->fresh();
}

function pirCreate(User $user, Plan $plan, string $number, ?string $key = null, bool $consented = true): Purchase
{
    return puxService()->create($user, $plan, $number, null, $key ?? (string) Str::uuid(), null, $consented);
}

function pirBuy(User $user, Plan $plan, string $number, ?string $key = null, bool $consented = true): Purchase
{
    return puxService()->purchase($user, $plan, $number, null, $key ?? (string) Str::uuid(), null, $consented);
}

/** The exact refusal message, or null when nothing was refused. */
function pirRefusal(Closure $call): ?string
{
    try {
        $call();
    } catch (PurchaseException $e) {
        return $e->getMessage();
    }

    return null;
}

/** Nothing was bought: no purchase, identity recipient, purchase transaction or provider call, and the wallet is untouched. */
function pirNothingBought(User $user, int $balanceKobo): void
{
    expect(Purchase::count())->toBe(0)
        ->and(PurchaseIdentityRecipient::count())->toBe(0)
        ->and(Transaction::where('type', 'purchase')->count())->toBe(0)
        ->and(FakeProvider::$calls)->toBe([])
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe($balanceKobo);
}

/** A pending purchase built directly through the model, to exercise the model guards. */
function pirDirect(Plan $plan, array $attributes = []): Purchase
{
    $user = User::factory()->create();
    $wallet = app(WalletService::class)->walletFor($user);

    return tap((new Purchase)->forceFill($attributes + [
        'reference' => WalletService::reference('PUR'), 'user_id' => $user->id, 'wallet_id' => $wallet->id, 'plan_id' => $plan->id,
        'service_id' => $plan->product->service_id, 'service_name' => $plan->product->service->name, 'product_name' => $plan->product->name,
        'plan_name' => $plan->name, 'network' => $plan->product->network, 'user_type' => $user->user_type, 'amount_type' => $plan->amount_type,
        'amount_kobo' => 15_000, 'status' => PurchaseStatus::Pending, 'idempotency_key' => (string) Str::uuid(),
    ]))->save();
}

/** Phone recipient and its Phase 10 fingerprint for a direct purchase of $plan. */
function pirPhone(Plan $plan, string $phone = '08012345678'): array
{
    return ['recipient' => $phone, 'request_fingerprint' => Purchase::fingerprint($plan->id, $phone, null)];
}

/** Records every log line (message and context) written from now on. */
function pirRecordLogs(): ArrayObject
{
    $lines = new ArrayObject;
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $lines->append($event->message.' '.json_encode($event->context)));

    return $lines;
}

/** Every row of every table, as JSON, to scan for a plain number. */
function pirDatabaseDump(): string
{
    return collect(DB::select("select name from sqlite_master where type = 'table'"))->pluck('name')
        ->map(fn (string $table) => $table.': '.json_encode(DB::table($table)->get()))->implode("\n");
}

/** Makes $key the app key and $previous the APP_PREVIOUS_KEYS, as a deployment would. */
function pirUseKeys(string $key, array $previous = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function pirNewKey(): string
{
    return 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
}

function pirStaff(): SystemUser
{
    (new RolesAndPermissionsSeeder)->run();
    $staff = SystemUser::factory()->create();
    $staff->assignRole('super-admin');

    return $staff;
}

/** Wallet, ledger and purchase integrity checks both pass. */
function pirClean(): void
{
    expect(Artisan::call('wallet:verify'))->toBe(0)
        ->and(Artisan::call('purchases:verify'))->toBe(0, Artisan::output());
}

describe('recipient types', function () {
    it('maps NIN and BVN to their own types, Exam PIN to none (CP4), Smile Data to no type yet, and every other service to phone', function () {
        expect(array_map(fn (RecipientType $type) => $type->value, RecipientType::cases()))->toBe(['phone', 'nin', 'bvn', 'none'])
            ->and(RecipientType::forServiceSlug('nin'))->toBe(RecipientType::Nin)
            ->and(RecipientType::forServiceSlug('bvn'))->toBe(RecipientType::Bvn)
            ->and(RecipientType::forServiceSlug('exam-pin'))->toBe(RecipientType::None)
            ->and(RecipientType::forServiceSlug('smile-data'))->toBeNull();
        foreach (['data', 'airtime', 'airtime-to-cash', 'alpha-topup', 'cable-tv', 'electricity', 'bills-payment', 'withdraw', 'referral-and-commission', 'other-service'] as $slug) {
            expect(RecipientType::forServiceSlug($slug))->toBe(RecipientType::Phone);
        }
    });

    it('refuses Smile Data purchases with no purchase, debit or provider call', function (string $slug) {
        $plan = puxPlan($slug, 10_000);
        puxRoute($plan);
        $user = puxCustomer(100_000);

        foreach (['08012345678', pirNumber()] as $recipient) {
            expect(pirRefusal(fn () => puxService()->purchase($user, $plan, $recipient, null, (string) Str::uuid(), null, true)))
                ->toBe('This service is not available yet.');
        }
        pirNothingBought($user, 100_000);
    })->with(['smile-data']);
});

describe('NIN and BVN input', function () {
    it('accepts exactly 11 digits, ignoring spaces', function (RecipientType $type) {
        $plan = pirPlan($type);
        $number = pirNumber();
        $spaced = ' '.substr($number, 0, 4).' '.substr($number, 4, 4)."\t".substr($number, 8).' ';

        $purchase = pirCreate(puxCustomer(100_000), $plan, $spaced);

        expect($purchase->recipient_type)->toBe($type)
            ->and($purchase->identityRecipient->number($type))->toBe($number)
            ->and($type->normalize($number))->toBe($number)
            ->and($type->isCanonical($spaced))->toBeFalse();
    })->with('pir identity types');

    it('refuses anything but 11 digits with the exact message, creating nothing', function (RecipientType $type) {
        $plan = pirPlan($type);
        $user = puxCustomer(100_000);
        $number = pirNumber();
        $inputs = [
            'letters' => 'ABCDEFGHIJK',
            'a letter inside' => substr($number, 0, 10).'A',
            '10 digits' => substr($number, 0, 10),
            '12 digits' => $number.'7',
            'empty' => '',
            'spaces only' => '   ',
            'phone with +234' => '+2348012345678',
            'spaced phone with +234' => '+234 801 234 5678',
            'dashes' => substr($number, 0, 5).'-'.substr($number, 5),
            'dots' => substr($number, 0, 5).'.'.substr($number, 5),
            'non-ASCII digits' => '١٢٣٤٥٦٧٨٩٠١',
            '10 digits and a newline' => substr($number, 0, 10)."\n",
        ];

        foreach ($inputs as $label => $input) {
            expect(pirRefusal(fn () => pirCreate($user, $plan, $input)))->toBe("Enter a valid 11-digit {$type->label()}.", $label);
        }
        pirNothingBought($user, 100_000);
    })->with('pir identity types');

    it('requires consent, creating nothing without it', function (RecipientType $type) {
        $plan = pirPlan($type);
        $user = puxCustomer(100_000);
        $number = pirNumber();

        expect(pirRefusal(fn () => puxService()->purchase($user, $plan, $number, null, (string) Str::uuid())))
            ->toBe("Your consent is required for a {$type->label()} purchase.")
            ->and(pirRefusal(fn () => pirBuy($user, $plan, $number, consented: false)))
            ->toBe("Your consent is required for a {$type->label()} purchase.");
        pirNothingBought($user, 100_000);
    })->with('pir identity types');

    it('refuses variable-price NIN and BVN plans', function (RecipientType $type) {
        $plan = pirPlan($type, variable: true);
        $user = puxCustomer(100_000);

        foreach ([10_000, null] as $faceValueKobo) {
            expect(pirRefusal(fn () => puxService()->purchase($user, $plan, pirNumber(), $faceValueKobo, (string) Str::uuid(), null, true)))
                ->toBe("{$type->label()} plans are sold at a fixed price only.");
        }
        pirNothingBought($user, 100_000);
    })->with('pir identity types');

    it('refuses new NIN/BVN purchases during maintenance but still returns a repeated submission', function (RecipientType $type) {
        $this->seed(SettingsSeeder::class);
        $plan = pirPlan($type);
        $user = puxCustomer(100_000);
        $number = pirNumber();
        FakeProvider::$purchaseScript = ['succeeded'];
        $first = pirBuy($user, $plan, $number, 'repeat');
        app(SettingsStore::class)->set(MaintenanceMode::SETTING, true);

        expect(pirRefusal(fn () => pirBuy($user, $plan, pirNumber())))->toBe(MaintenanceMode::MESSAGE)
            ->and(pirRefusal(fn () => pirBuy($user, $plan, pirNumber(), consented: false)))->toBe(MaintenanceMode::MESSAGE)
            ->and(pirBuy($user, $plan, $number, 'repeat')->id)->toBe($first->id)
            ->and(Purchase::count())->toBe(1)
            ->and(PurchaseIdentityRecipient::count())->toBe(1)
            ->and(Transaction::where('type', 'purchase')->count())->toBe(1)
            ->and(FakeProvider::$calls)->toHaveCount(1);
    })->with('pir identity types');
});

describe('storage', function () {
    it('stores no phone recipient or fingerprint, and one encrypted, masked, keyed identity recipient with consent', function (RecipientType $type) {
        $this->freezeTime();
        $plan = pirPlan($type);
        $number = pirNumber();

        $purchase = pirCreate(puxCustomer(100_000), $plan, $number);

        $row = DB::table('purchases')->where('id', $purchase->id)->sole();
        $identity = DB::table('purchase_identity_recipients')->sole();
        $appKey = app('encrypter')->getKey();
        $lookupKey = hash_hkdf('sha256', $appKey, 32, 'nadabo:purchase-identity:lookup:v1');
        $fingerprintKey = hash_hkdf('sha256', $appKey, 32, 'nadabo:purchase-identity:fingerprint:v1');
        expect($row->recipient_type)->toBe($type->value)
            ->and($row->recipient)->toBeNull()
            ->and($row->request_fingerprint)->toBeNull()
            ->and((int) $identity->purchase_id)->toBe($purchase->id)
            ->and($identity->encrypted_value)->not->toContain($number)
            ->and(Crypt::decryptString($identity->encrypted_value))->toBe($number)
            ->and($identity->masked_value)->toBe('•••••••'.substr($number, -4))
            ->and($identity->lookup_hash)->toBe(hash_hmac('sha256', "{$type->value}:{$number}", $lookupKey))
            ->and($identity->keyed_fingerprint)->toBe(hash_hmac('sha256', "{$plan->id}|{$type->value}|{$number}|-", $fingerprintKey))
            ->and($identity->lookup_hash)->not->toBe(hash('sha256', "{$type->value}:{$number}"))
            ->and($identity->keyed_fingerprint)->not->toBe(hash('sha256', "{$plan->id}|{$type->value}|{$number}|-"))
            ->and($identity->consented_at)->toBe(now()->format('Y-m-d H:i:s'))
            ->and($identity->created_at)->toBe(now()->format('Y-m-d H:i:s'));
    })->with('pir identity types');

    it('never stores or logs the plain number, across delivery, failover, refunds, re-checks and review', function (RecipientType $type) {
        $logs = pirRecordLogs();
        $staff = pirStaff();
        $plan = pirPlan($type, routes: 2);
        $user = puxCustomer(200_000);
        $numbers = [pirNumber(), pirNumber(), pirNumber(), pirNumber()];

        $fixtures = [FakeProvider::fixtureFields(), FakeProvider::fixtureFields()]; // CP2: a NIN/BVN success needs its result
        FakeProvider::$purchaseScript = ['failed_definite', 'succeeded'];
        FakeProvider::$resultScript = [$fixtures[0]];
        $delivered = pirBuy($user, $plan, $numbers[0]);
        FakeProvider::$purchaseScript = ['failed_definite', 'failed_definite'];
        $refunded = pirBuy($user, $plan, $numbers[1]);
        FakeProvider::$purchaseScript = ['timeout'];
        $rechecked = pirBuy($user, $plan, $numbers[2]);
        FakeProvider::$purchaseScript = ['timeout'];
        $reviewed = pirBuy($user, $plan, $numbers[3]);
        $this->travel(3)->minutes();
        FakeProvider::$queryScript = ['succeeded', 'unknown'];
        FakeProvider::$resultScript = [$fixtures[1]];
        Artisan::call('purchases:reconcile');
        $this->travel(25)->hours();
        FakeProvider::$queryScript = ['unknown'];
        Artisan::call('purchases:reconcile');
        expect($reviewed->fresh()->status)->toBe(PurchaseStatus::Review);
        FakeProvider::$queryScript = ['failed_definite'];
        app(RecheckPurchase::class)->handle($reviewed->fresh(), $staff);
        Artisan::call('purchases:verify');
        $verifyOutput = Artisan::output();

        expect($delivered->fresh()->status)->toBe(PurchaseStatus::Successful)
            ->and($refunded->fresh()->status)->toBe(PurchaseStatus::Failed)
            ->and($rechecked->fresh()->status)->toBe(PurchaseStatus::Successful)
            ->and($reviewed->fresh()->status)->toBe(PurchaseStatus::Failed)
            ->and(PurchaseIdentityRecipient::count())->toBe(4);
        $dump = pirDatabaseDump();
        foreach ($numbers as $number) {
            expect($dump)->not->toContain($number)
                ->and(implode("\n", $logs->getArrayCopy()))->not->toContain($number)
                ->and($verifyOutput)->not->toContain($number);
        }
        foreach ([...$fixtures[0]->all(), ...$fixtures[1]->all()] as $field) {
            expect($dump)->not->toContain($field['value'])
                ->and(implode("\n", $logs->getArrayCopy()))->not->toContain($field['value'])
                ->and($verifyOutput)->not->toContain($field['value']);
        }
        pirClean();
    })->with('pir identity types');

    it('keeps NIN and BVN apart: the same digits hash differently under each type', function () {
        $number = pirNumber();
        expect(IdentityHasher::lookupHash(RecipientType::Nin, $number))->not->toBe(IdentityHasher::lookupHash(RecipientType::Bvn, $number))
            ->and(IdentityHasher::keyedFingerprint(1, RecipientType::Nin, $number, null))->not->toBe(IdentityHasher::keyedFingerprint(1, RecipientType::Bvn, $number, null));

        $user = puxCustomer(100_000);
        $nin = pirCreate($user, pirPlan(RecipientType::Nin), $number);
        $bvn = pirCreate($user, pirPlan(RecipientType::Bvn), $number);
        $find = fn (RecipientType $type) => PurchaseIdentityRecipient::whereIn('lookup_hash', IdentityHasher::lookupHashes($type, $number))->pluck('purchase_id')->all();

        expect($find(RecipientType::Nin))->toBe([$nin->id])
            ->and($find(RecipientType::Bvn))->toBe([$bvn->id])
            ->and(fn () => IdentityHasher::lookupHash(RecipientType::Phone, '08012345678'))->toThrow(LogicException::class);
    });

    it('never serialises the encrypted value or the keyed hashes', function () {
        $number = pirNumber();
        $purchase = pirCreate(puxCustomer(100_000), pirPlan(RecipientType::Nin), $number);
        $identity = $purchase->identityRecipient;
        $loaded = Purchase::with('identityRecipient')->findOrFail($purchase->id);

        expect(array_keys($identity->toArray()))->toEqualCanonicalizing(['id', 'purchase_id', 'masked_value', 'consented_at', 'created_at'])
            ->and(array_keys(json_decode($identity->toJson(), true)))->toEqualCanonicalizing(['id', 'purchase_id', 'masked_value', 'consented_at', 'created_at'])
            ->and(array_keys($loaded->toArray()['identity_recipient']))->toEqualCanonicalizing(['id', 'purchase_id', 'masked_value', 'consented_at', 'created_at'])
            ->and($loaded->toJson())->not->toContain($number)
            ->and((string) $identity)->not->toContain($number)
            ->and(print_r($loaded->toArray(), true))->not->toContain($number);
    });

    it('reads the encrypted value only in the identity model and the purchase engine, and shows it on no page', function () {
        $sources = collect([app_path(), resource_path(), base_path('routes'), config_path()])
            ->flatMap(fn (string $dir) => File::allFiles($dir))
            ->mapWithKeys(fn ($file) => [Str::after($file->getPathname(), base_path().'/') => $file->getContents()]);
        $using = fn (string $needle) => $sources->filter(fn (string $code) => str_contains($code, $needle))->keys()->sort()->values()->all();

        expect($using('encrypted_value'))->toBe(['app/Models/PurchaseIdentityRecipient.php', 'app/Services/Purchases/PurchaseService.php'])
            ->and($using('->number('))->toBe(['app/Models/PurchaseIdentityRecipient.php', 'app/Services/Purchases/PurchaseService.php'])
            ->and($using('isReadable('))->toBe(['app/Console/Commands/VerifyPurchasesCommand.php', 'app/Models/PurchaseIdentityRecipient.php'])
            ->and($sources->filter(fn ($code, $path) => str_starts_with($path, 'resources/') || str_starts_with($path, 'routes/'))
                ->filter(fn (string $code) => preg_match('/identityRecipient|identity_recipient|IdentityRecipient/', $code))->keys()->all())->toBe([]);
    });
});

describe('model guards', function () {
    it('keeps the Phase 10 phone guard: only a canonical phone with its Phase 10 fingerprint', function () {
        $plan = puxPlan('data', 10_000);
        $number = pirNumber();

        foreach ([null, '', '•••••••'.substr($number, -4), $number, '+2348012345678', '0801234567'] as $recipient) {
            expect(fn () => pirDirect($plan, ['recipient' => $recipient, 'request_fingerprint' => str_repeat('a', 64)]))
                ->toThrow(PurchaseException::class, 'The recipient must be stored in canonical phone format.');
        }
        foreach ([null, '', str_repeat('a', 64), IdentityHasher::keyedFingerprint($plan->id, RecipientType::Nin, $number, null)] as $fingerprint) {
            expect(fn () => pirDirect($plan, ['recipient' => '08012345678', 'request_fingerprint' => $fingerprint]))
                ->toThrow(PurchaseException::class, 'The request fingerprint does not match the purchase details.');
        }
        expect(Purchase::count())->toBe(0)
            ->and(pirDirect($plan, pirPhone($plan))->fresh()->recipient_type)->toBe(RecipientType::Phone)
            ->and(pirDirect($plan, ['recipient_type' => RecipientType::Phone] + pirPhone($plan))->fresh()->recipient)->toBe('08012345678');
    });

    it('stores a purchase created without a type as a phone purchase, like the column default', function () {
        $plan = puxPlan('airtime', 10_000);
        $purchase = pirDirect($plan, pirPhone($plan));

        expect($purchase->recipient_type)->toBe(RecipientType::Phone)
            ->and(DB::table('purchases')->where('id', $purchase->id)->value('recipient_type'))->toBe('phone')
            ->and($purchase->identityRecipient)->toBeNull();
    });

    it('refuses any phone recipient or phone fingerprint on a NIN/BVN purchase, even an empty string', function (RecipientType $type) {
        $plan = pirPlan($type);
        $number = pirNumber();
        $cases = [
            ['recipient' => ''], ['recipient' => '08012345678'], ['recipient' => $number], ['recipient' => $type->mask($number)],
            ['request_fingerprint' => ''], ['request_fingerprint' => IdentityHasher::keyedFingerprint($plan->id, $type, $number, null)],
            ['request_fingerprint' => Purchase::fingerprint($plan->id, '08012345678', null)],
            pirPhone($plan),
        ];

        foreach ($cases as $attributes) {
            expect(fn () => pirDirect($plan, ['recipient_type' => $type] + $attributes))
                ->toThrow(PurchaseException::class, 'A NIN or BVN purchase stores no phone recipient or phone fingerprint.');
        }
        $purchase = pirDirect($plan, ['recipient_type' => $type])->fresh();
        expect($purchase->recipient)->toBeNull()
            ->and($purchase->request_fingerprint)->toBeNull()
            ->and($purchase->recipient_type)->toBe($type)
            ->and(Purchase::count())->toBe(1);
    })->with('pir identity types');

    it('takes the recipient type from the service, and never changes it', function () {
        $data = puxPlan('data', 10_000);
        $nin = pirPlan(RecipientType::Nin);
        $bvn = pirPlan(RecipientType::Bvn);
        $message = "The recipient type must be the one the purchase's service uses.";

        expect(fn () => pirDirect($data, ['recipient_type' => RecipientType::Nin]))->toThrow(PurchaseException::class, $message)
            ->and(fn () => pirDirect($data, ['recipient_type' => RecipientType::Bvn]))->toThrow(PurchaseException::class, $message)
            ->and(fn () => pirDirect($nin, pirPhone($nin)))->toThrow(PurchaseException::class, $message) // no type: phone, the default
            ->and(fn () => pirDirect($nin, ['recipient_type' => RecipientType::Phone] + pirPhone($nin)))->toThrow(PurchaseException::class, $message)
            ->and(fn () => pirDirect($nin, ['recipient_type' => RecipientType::Bvn]))->toThrow(PurchaseException::class, $message)
            ->and(fn () => pirDirect($bvn, ['recipient_type' => RecipientType::Nin]))->toThrow(PurchaseException::class, $message);
        foreach (['exam-pin', 'smile-data'] as $slug) {
            $plan = puxPlan($slug, 10_000);
            expect(fn () => pirDirect($plan, pirPhone($plan)))->toThrow(PurchaseException::class, $message);
        }
        expect(Purchase::count())->toBe(0);

        $phone = pirDirect($data, pirPhone($data));
        $identity = pirDirect($nin, ['recipient_type' => RecipientType::Nin]);
        expect(fn () => $phone->fresh()->forceFill(['recipient_type' => RecipientType::Nin])->save())
            ->toThrow(LogicException::class, 'Purchase fields are immutable: recipient_type.')
            ->and(fn () => $phone->fresh()->forceFill(['recipient_type' => RecipientType::Nin, 'recipient' => null, 'request_fingerprint' => null])->save())
            ->toThrow(LogicException::class, 'recipient_type')
            ->and(fn () => $identity->fresh()->forceFill(['recipient_type' => RecipientType::Bvn])->save())
            ->toThrow(LogicException::class, 'Purchase fields are immutable: recipient_type.')
            ->and(fn () => $identity->fresh()->forceFill(['recipient_type' => RecipientType::Phone])->save())
            ->toThrow(LogicException::class, 'Purchase fields are immutable: recipient_type.')
            ->and($phone->fresh()->recipient_type)->toBe(RecipientType::Phone)
            ->and($identity->fresh()->recipient_type)->toBe(RecipientType::Nin);
    });

    it('accepts one identity recipient, only for a NIN/BVN purchase being created, with matching values and consent', function () {
        $type = RecipientType::Nin;
        $plan = pirPlan($type);
        $number = pirNumber();
        $other = pirNumber();
        $write = fn (Purchase $purchase, array $overrides = []) => (new PurchaseIdentityRecipient)->forceFill($overrides + [
            'purchase_id' => $purchase->id, 'encrypted_value' => $number, 'masked_value' => $type->mask($number),
            'lookup_hash' => IdentityHasher::lookupHash($type, $number),
            'keyed_fingerprint' => IdentityHasher::keyedFingerprint($purchase->plan_id, $type, $number, $purchase->face_value_kobo),
            'consented_at' => now(),
        ])->save();
        $wrongPurchase = 'An identity recipient belongs only to a NIN or BVN purchase that is being created.';

        $data = puxPlan('data', 10_000);
        expect(fn () => $write(pirDirect($data, pirPhone($data))))->toThrow(PurchaseException::class, $wrongPurchase);
        $review = pirDirect($plan, ['recipient_type' => $type]);
        $review->forceFill(['status' => PurchaseStatus::Review])->save();
        expect(fn () => $write($review))->toThrow(PurchaseException::class, $wrongPurchase);

        $pending = pirDirect($plan, ['recipient_type' => $type]);
        $mismatches = [
            '10 digits' => ['encrypted_value' => substr($number, 0, 10)],
            'with a space' => ['encrypted_value' => ' '.$number],
            'missing' => ['encrypted_value' => null],
            'mask of another number' => ['masked_value' => $type->mask($other)],
            'full number as the mask' => ['masked_value' => $number],
            'BVN lookup hash' => ['lookup_hash' => IdentityHasher::lookupHash(RecipientType::Bvn, $number)],
            'unkeyed lookup hash' => ['lookup_hash' => hash('sha256', "nin:{$number}")],
            'lookup hash without the type' => ['lookup_hash' => hash_hmac('sha256', $number, 'x')],
            'fingerprint of another plan' => ['keyed_fingerprint' => IdentityHasher::keyedFingerprint($plan->id + 1, $type, $number, null)],
            'fingerprint of another number' => ['keyed_fingerprint' => IdentityHasher::keyedFingerprint($plan->id, $type, $other, null)],
            'no consent' => ['consented_at' => null],
        ];
        foreach ($mismatches as $label => $overrides) {
            expect(fn () => $write($pending, $overrides))->toThrow(PurchaseException::class);
            expect(PurchaseIdentityRecipient::count())->toBe(0, $label);
        }

        $write($pending);
        expect(fn () => $write($pending))->toThrow(PurchaseException::class, 'This purchase already has its identity recipient.');

        $identity = PurchaseIdentityRecipient::sole();
        expect(fn () => $identity->forceFill(['masked_value' => $type->mask($other)])->save())->toThrow(LogicException::class, 'never change')
            ->and(fn () => PurchaseIdentityRecipient::sole()->save())->toThrow(LogicException::class, 'never change')
            ->and(fn () => PurchaseIdentityRecipient::sole()->delete())->toThrow(LogicException::class, 'never deleted')
            ->and(fn () => DB::table('purchases')->where('id', $pending->id)->delete())->toThrow(QueryException::class)
            ->and(PurchaseIdentityRecipient::sole()->masked_value)->toBe($type->mask($number))
            ->and(PurchaseIdentityRecipient::sole()->number($type))->toBe($number);
    });

    it('keeps the phone fingerprint byte-identical to Phase 10', function () {
        expect(Purchase::fingerprint(7, '+234 801 234 5678', null))->toBe('8b2af6b0d92c360fedc94ced7b25e4163efe055663361aa6cf074bc31fc68f1f')
            ->and(Purchase::fingerprint(7, '08012345678', null))->toBe(hash('sha256', '7|08012345678|-'))
            ->and(Purchase::fingerprint(12, '0803 000 1111', 150_000))->toBe(hash('sha256', '12|08030001111|150000'))
            ->and(fn () => Purchase::fingerprint(7, pirNumber(), null))->toThrow(InvalidArgumentException::class);

        $data = puxPlan('data', 10_000);
        puxRoute($data);
        $airtime = puxPlan('airtime', 0, true);
        puxRoute($airtime);
        $user = puxCustomer(200_000);

        expect(puxService()->create($user, $data, '+234 801 234 5678', null, (string) Str::uuid())->fresh()->request_fingerprint)
            ->toBe(hash('sha256', $data->id.'|08012345678|-'))
            ->and(puxService()->create($user, $airtime, '0801-234-5678', 20_000, (string) Str::uuid())->fresh()->request_fingerprint)
            ->toBe(hash('sha256', $airtime->id.'|08012345678|20000'));
    });
});

describe('repeated requests', function () {
    it('returns the same NIN/BVN purchase for the same key and number, with one debit and one provider call', function (RecipientType $type) {
        $plan = pirPlan($type);
        $user = puxCustomer(100_000);
        $number = pirNumber();
        FakeProvider::$purchaseScript = ['succeeded'];

        $first = pirBuy($user, $plan, $number, 'same');
        $again = pirBuy($user, $plan, implode(' ', str_split($number, 4)), 'same');

        expect($again->id)->toBe($first->id)
            ->and(Purchase::count())->toBe(1)
            ->and(PurchaseIdentityRecipient::count())->toBe(1)
            ->and(Transaction::where('type', 'purchase')->count())->toBe(1)
            ->and(FakeProvider::$calls)->toHaveCount(1)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
    })->with('pir identity types');

    it('refuses a reused key for another number, plan, type or a phone, never comparing across types', function () {
        $user = puxCustomer(200_000);
        $nin = pirPlan(RecipientType::Nin);
        $otherNin = pirPlan(RecipientType::Nin);
        $bvn = pirPlan(RecipientType::Bvn);
        $data = puxPlan('data', 10_000);
        puxRoute($data);
        $number = pirNumber();
        $message = 'This request was already used for a different purchase. Please start again.';

        $ninPurchase = pirCreate($user, $nin, $number, 'nin-key');
        $phonePurchase = puxService()->create($user, $data, '08012345678', null, 'phone-key');

        expect(pirRefusal(fn () => pirCreate($user, $nin, pirNumber(), 'nin-key')))->toBe($message)
            ->and(pirRefusal(fn () => pirCreate($user, $otherNin, $number, 'nin-key')))->toBe($message)
            ->and(pirRefusal(fn () => pirCreate($user, $bvn, $number, 'nin-key')))->toBe($message)
            ->and(pirRefusal(fn () => puxService()->create($user, $data, '08012345678', null, 'nin-key')))->toBe($message)
            ->and(pirRefusal(fn () => pirCreate($user, $nin, $number, 'phone-key')))->toBe($message)
            ->and(pirRefusal(fn () => pirCreate($user, $nin, '08012345678', 'phone-key')))->toBe($message)
            ->and(pirCreate($user, $nin, $number, 'nin-key')->id)->toBe($ninPurchase->id)
            ->and(puxService()->create($user, $data, '+2348012345678', null, 'phone-key')->id)->toBe($phonePurchase->id)
            ->and(Purchase::count())->toBe(2)
            ->and(Transaction::where('type', 'purchase')->count())->toBe(2);
    });
});

describe('app key rotation', function () {
    it('still matches a repeated request, finds, reads and delivers a NIN purchase made under the previous app key', function () {
        $plan = pirPlan(RecipientType::Nin);
        $user = puxCustomer(100_000);
        $number = pirNumber();
        $oldKey = config('app.key');
        $purchase = pirCreate($user, $plan, $number, 'rotate');
        $storedLookup = PurchaseIdentityRecipient::sole()->lookup_hash;

        pirUseKeys(pirNewKey(), [$oldKey]);

        expect(pirCreate($user, $plan, $number, 'rotate')->id)->toBe($purchase->id)
            ->and(pirRefusal(fn () => pirCreate($user, $plan, pirNumber(), 'rotate')))
            ->toBe('This request was already used for a different purchase. Please start again.')
            ->and(PurchaseIdentityRecipient::whereIn('lookup_hash', IdentityHasher::lookupHashes(RecipientType::Nin, $number))->pluck('purchase_id')->all())
            ->toBe([$purchase->id])
            ->and(IdentityHasher::lookupHash(RecipientType::Nin, $number))->not->toBe($storedLookup)
            ->and(PurchaseIdentityRecipient::sole()->number(RecipientType::Nin))->toBe($number)
            ->and(Purchase::count())->toBe(1);

        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [FakeProvider::fixtureFields()]; // CP2: a NIN/BVN success needs its result
        expect(puxService()->execute($purchase)->status)->toBe(PurchaseStatus::Successful)
            ->and(FakeProvider::$calls[0]->recipient)->toBe($number);
        $later = pirCreate($user, $plan, $number, 'after-rotation');
        expect($later->identityRecipient->lookup_hash)->toBe(IdentityHasher::lookupHash(RecipientType::Nin, $number));
        pirClean();
    });

    it('cannot match, find, read or send it once the old key is dropped, and purchases:verify reports it', function () {
        $plan = pirPlan(RecipientType::Nin);
        $user = puxCustomer(100_000);
        $number = pirNumber();
        $purchase = pirCreate($user, $plan, $number, 'rotate');

        pirUseKeys(pirNewKey());

        expect(pirRefusal(fn () => pirCreate($user, $plan, $number, 'rotate')))
            ->toBe('This request was already used for a different purchase. Please start again.')
            ->and(PurchaseIdentityRecipient::whereIn('lookup_hash', IdentityHasher::lookupHashes(RecipientType::Nin, $number))->count())->toBe(0)
            ->and(PurchaseIdentityRecipient::sole()->number(RecipientType::Nin))->toBeNull()
            ->and(puxService()->execute($purchase)->status)->toBe(PurchaseStatus::Pending)
            ->and(FakeProvider::$calls)->toBe([])
            ->and(PurchaseAttempt::count())->toBe(0)
            ->and(Artisan::call('purchases:verify'))->toBe(1)
            ->and(Artisan::output())->toContain("Purchase {$purchase->reference} (pending): its NIN cannot be read")->not->toContain($number);
    });
});

describe('provider requests', function () {
    it('sends a phone purchase exactly the Phase 10 request', function () {
        $data = puxPlan('data', 10_000);
        puxRoute($data);
        $airtime = puxPlan('airtime', 0, true);
        puxRoute($airtime);
        $user = puxCustomer(200_000);
        FakeProvider::$purchaseScript = ['succeeded', 'succeeded'];

        $bundle = puxService()->purchase($user, $data, '+234 801 234 5678', null, (string) Str::uuid());
        $topUp = puxService()->purchase($user, $airtime, '0803 000 1111', 20_000, (string) Str::uuid());

        expect(FakeProvider::$calls)->toHaveCount(2)
            ->and(get_object_vars(FakeProvider::$calls[0]))->toBe([
                'requestReference' => $bundle->attempts->sole()->request_reference, 'serviceSlug' => 'data', 'network' => 'mtn',
                'providerPlanCode' => 'CODE1', 'recipient' => '08012345678', 'amountKobo' => 10_000, 'faceValueKobo' => null, 'recipientType' => 'phone',
            ])
            ->and(get_object_vars(FakeProvider::$calls[1]))->toBe([
                'requestReference' => $topUp->attempts->sole()->request_reference, 'serviceSlug' => 'airtime', 'network' => 'mtn',
                'providerPlanCode' => 'CODE1', 'recipient' => '08030001111', 'amountKobo' => 19_600, 'faceValueKobo' => 20_000, 'recipientType' => 'phone',
            ]);
    });

    it('sends a NIN/BVN purchase its number and type, never shown in debug output', function (RecipientType $type) {
        $plan = pirPlan($type);
        $number = pirNumber();
        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [FakeProvider::fixtureFields()]; // CP2: a NIN/BVN success needs its result

        $purchase = pirBuy(puxCustomer(100_000), $plan, $number);
        $request = FakeProvider::$calls[0];
        ob_start();
        var_dump($request);
        $dumped = ob_get_clean();

        expect(FakeProvider::$calls)->toHaveCount(1)
            ->and(get_object_vars($request))->toBe([
                'requestReference' => $purchase->attempts->sole()->request_reference, 'serviceSlug' => $type->value, 'network' => null,
                'providerPlanCode' => 'CODE1', 'recipient' => $number, 'amountKobo' => 15_000, 'faceValueKobo' => null, 'recipientType' => $type->value,
            ])
            ->and(print_r($request, true))->not->toContain($number)->toContain('[redacted]')->toContain($type->value)
            ->and($dumped)->not->toContain($number)
            ->and($purchase->status)->toBe(PurchaseStatus::Successful);
    })->with('pir identity types');
});

describe('fail closed', function () {
    it('makes no attempt and no provider call when the NIN cannot be read, logs only the reference, and stays visible', function (string $damage) {
        $logs = pirRecordLogs();
        $plan = pirPlan(RecipientType::Nin);
        $user = puxCustomer(100_000);
        $number = pirNumber();
        $purchase = pirCreate($user, $plan, $number);
        match ($damage) {
            'missing' => DB::table('purchase_identity_recipients')->delete(),
            'unreadable' => pirUseKeys(pirNewKey()),
            'not a number' => DB::table('purchase_identity_recipients')->update(['encrypted_value' => Crypt::encryptString('not-a-number')]),
        };
        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$queryScript = ['succeeded'];

        expect(puxService()->execute($purchase)->status)->toBe(PurchaseStatus::Pending);
        $this->travel(5)->minutes();
        Artisan::call('purchases:reconcile');
        app(RecheckPurchase::class)->handle($purchase->fresh(), pirStaff());

        $purchase->refresh();
        $unavailable = array_values(array_filter($logs->getArrayCopy(), fn (string $line) => str_starts_with($line, 'Purchase recipient unavailable')));
        expect($purchase->status)->toBe(PurchaseStatus::Pending)
            ->and($purchase->refund_transaction_id)->toBeNull()
            ->and(PurchaseAttempt::count())->toBe(0)
            ->and(FakeProvider::$calls)->toBe([])
            ->and(Transaction::where('type', 'purchase')->count())->toBe(1)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000)
            ->and($unavailable)->toHaveCount(3)
            ->and(array_unique($unavailable))->toBe(['Purchase recipient unavailable {"purchase":"'.$purchase->reference.'"}'])
            ->and(implode("\n", $logs->getArrayCopy()))->not->toContain($number);

        $this->travel(config('purchases.overdue_after_minutes') + 1)->minutes();
        expect(Purchase::checkOverdue()->pluck('id')->all())->toBe([$purchase->id])
            ->and(Artisan::call('purchases:verify'))->toBe(1)
            ->and(Artisan::output())->toContain("Purchase {$purchase->reference} (pending)")->not->toContain($number);
    })->with(['missing', 'unreadable', 'not a number']);

    it('sends nothing for a phone purchase whose recipient is missing either', function () {
        $logs = pirRecordLogs();
        $plan = puxPlan('data', 10_000);
        puxRoute($plan);
        $purchase = puxService()->create(puxCustomer(100_000), $plan, '08012345678', null, (string) Str::uuid());
        DB::table('purchases')->where('id', $purchase->id)->update(['recipient' => null]); // impossible through the model or the MariaDB CHECK
        FakeProvider::$purchaseScript = ['succeeded'];

        expect(puxService()->execute($purchase->fresh())->status)->toBe(PurchaseStatus::Pending)
            ->and(PurchaseAttempt::count())->toBe(0)
            ->and(FakeProvider::$calls)->toBe([])
            ->and($logs->getArrayCopy())->toBe(['Purchase recipient unavailable {"purchase":"'.$purchase->reference.'"}']);
    });
});

describe('NIN and BVN purchases follow the Phase 10 engine', function () {
    it('delivers after failing over a definite failure, with cost and margin', function (RecipientType $type) {
        $plan = pirPlan($type, routes: 2);
        $user = puxCustomer(100_000);
        FakeProvider::$purchaseScript = ['failed_definite', 'succeeded'];
        FakeProvider::$resultScript = [FakeProvider::fixtureFields()]; // CP2: a NIN/BVN success needs its result

        $purchase = pirBuy($user, $plan, pirNumber());

        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and($purchase->attempts)->toHaveCount(2)
            ->and($purchase->successfulAttempt->route_priority)->toBe(2)
            ->and($purchase->cost_kobo)->toBe(10_000)
            ->and($purchase->margin_kobo)->toBe(5_000)
            ->and($purchase->refund_transaction_id)->toBeNull()
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
        pirClean();
    })->with('pir identity types');

    it('fails with exactly one refund when every route fails definitely', function (RecipientType $type) {
        $plan = pirPlan($type, routes: 2);
        $user = puxCustomer(100_000);
        FakeProvider::$purchaseScript = ['failed_definite', 'failed_definite'];

        $purchase = pirBuy($user, $plan, pirNumber());
        puxService()->execute($purchase);
        puxService()->recheck($purchase, PurchaseSource::Reconcile);

        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Failed)
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(1)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(100_000);
        pirClean();
    })->with('pir identity types');

    it('settles an unclear outcome through reconciliation, never failing over', function (RecipientType $type) {
        $plan = pirPlan($type, routes: 2);
        $user = puxCustomer(100_000);
        FakeProvider::$purchaseScript = ['timeout'];
        $purchase = pirBuy($user, $plan, pirNumber());
        expect($purchase->status)->toBe(PurchaseStatus::Pending)->and(FakeProvider::$calls)->toHaveCount(1);

        $this->travel(3)->minutes();
        FakeProvider::$queryScript = ['succeeded'];
        FakeProvider::$resultScript = [FakeProvider::fixtureFields()]; // CP2: a NIN/BVN success needs its result
        Artisan::call('purchases:reconcile');

        $purchase->refresh();
        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and($purchase->attempts)->toHaveCount(1)
            ->and($purchase->attempts->sole()->status)->toBe(PurchaseAttemptStatus::Succeeded)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000);
        pirClean();
    })->with('pir identity types');

    it('moves to review after 24 hours and settles by a staff re-check with one refund', function (RecipientType $type) {
        $staff = pirStaff();
        $plan = pirPlan($type, routes: 2);
        $user = puxCustomer(100_000);
        FakeProvider::$purchaseScript = ['timeout'];
        $purchase = pirBuy($user, $plan, pirNumber());

        $this->travel(25)->hours();
        FakeProvider::$queryScript = ['unknown'];
        Artisan::call('purchases:reconcile');
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Review);

        FakeProvider::$queryScript = ['failed_definite'];
        app(RecheckPurchase::class)->handle($purchase->fresh(), $staff);
        app(RecheckPurchase::class)->handle($purchase->fresh(), $staff);

        $purchase->refresh();
        expect($purchase->status)->toBe(PurchaseStatus::Failed)
            ->and($purchase->attempts)->toHaveCount(1)
            ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(1)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(100_000);
        pirClean();
    })->with('pir identity types');
});

describe('purchases:verify', function () {
    it('passes phone, NIN and BVN purchases with the unchanged message', function () {
        $user = puxCustomer(200_000);
        $data = puxPlan('data', 10_000);
        puxRoute($data);
        FakeProvider::$purchaseScript = ['succeeded', 'succeeded', 'failed_definite', 'timeout'];
        FakeProvider::$resultScript = [null, FakeProvider::fixtureFields()]; // CP2: the phone success as before; the NIN success with its result
        puxService()->purchase($user, $data, '08012345678', null, (string) Str::uuid());
        pirBuy($user, pirPlan(RecipientType::Nin), pirNumber());
        pirBuy($user, pirPlan(RecipientType::Bvn), pirNumber());
        pirBuy($user, pirPlan(RecipientType::Nin), pirNumber());

        expect(Purchase::orderBy('id')->pluck('status')->map->value->all())->toBe(['successful', 'successful', 'failed', 'pending'])
            ->and(Artisan::call('purchases:verify'))->toBe(0)
            ->and(trim(Artisan::output()))->toBe('All 4 purchase(s) are consistent with their debits, refunds and attempts, and the purchase totals match.');
    });

    it('reports each recipient problem by reference only, never printing a number', function (string $target, Closure $tamper, string $problem) {
        $user = puxCustomer(100_000);
        $data = puxPlan('data', 10_000);
        puxRoute($data);
        $number = pirNumber();
        $phone = puxService()->create($user, $data, '08012345678', null, (string) Str::uuid());
        $nin = pirCreate($user, pirPlan(RecipientType::Nin), $number);
        $purchase = $target === 'phone' ? $phone : $nin;

        $tamper($purchase, $number);

        expect(Artisan::call('purchases:verify'))->toBe(1);
        $output = Artisan::output();
        expect($output)->toContain("Purchase {$purchase->reference} (pending): {$problem}")
            ->not->toContain($number)
            ->not->toContain('08012345678')
            ->toContain('1 problem(s) found in 2 purchase(s).');
    })->with([
        'phone without recipient' => ['phone', fn (Purchase $p) => DB::table('purchases')->where('id', $p->id)->update(['recipient' => null]),
            'a phone purchase without a canonical phone recipient.'],
        'phone not canonical' => ['phone', fn (Purchase $p) => DB::table('purchases')->where('id', $p->id)->update(['recipient' => '+2348012345678']),
            'a phone purchase without a canonical phone recipient.'],
        'phone without fingerprint' => ['phone', fn (Purchase $p) => DB::table('purchases')->where('id', $p->id)->update(['request_fingerprint' => null]),
            'a phone purchase without a request fingerprint.'],
        'phone with identity recipient' => ['phone', fn (Purchase $p, string $n) => DB::table('purchase_identity_recipients')->insert([
            'purchase_id' => $p->id, 'encrypted_value' => Crypt::encryptString($n), 'masked_value' => RecipientType::Nin->mask($n),
            'lookup_hash' => str_repeat('a', 64), 'keyed_fingerprint' => str_repeat('b', 64), 'consented_at' => now(), 'created_at' => now()]),
            'a phone purchase with an identity recipient.'],
        'NIN with a phone' => ['nin', fn (Purchase $p) => DB::table('purchases')->where('id', $p->id)->update(['recipient' => '08012345678']),
            'a NIN purchase that stores a phone recipient or phone fingerprint.'],
        'NIN with a fingerprint' => ['nin', fn (Purchase $p) => DB::table('purchases')->where('id', $p->id)->update(['request_fingerprint' => str_repeat('c', 64)]),
            'a NIN purchase that stores a phone recipient or phone fingerprint.'],
        'NIN without identity' => ['nin', fn () => DB::table('purchase_identity_recipients')->delete(),
            'a NIN purchase without its identity recipient.'],
        'unmasked' => ['nin', fn (Purchase $p, string $n) => DB::table('purchase_identity_recipients')->update(['masked_value' => $n]),
            "its identity recipient's display value is not masked."],
        'bad lookup hash' => ['nin', fn () => DB::table('purchase_identity_recipients')->update(['lookup_hash' => 'not-a-hash']),
            "its identity recipient's lookup hash or fingerprint is not a keyed hash."],
        'bad fingerprint' => ['nin', fn () => DB::table('purchase_identity_recipients')->update(['keyed_fingerprint' => str_repeat('A', 64)]),
            "its identity recipient's lookup hash or fingerprint is not a keyed hash."],
        'plain number stored' => ['nin', fn (Purchase $p, string $n) => DB::table('purchase_identity_recipients')->update(['encrypted_value' => $n]),
            'its NIN cannot be read'],
        'another key' => ['nin', fn (Purchase $p, string $n) => DB::table('purchase_identity_recipients')
            ->update(['encrypted_value' => (new Encrypter(Encrypter::generateKey(config('app.cipher')), config('app.cipher')))->encryptString($n)]),
            'its NIN cannot be read'],
    ]);
});
