<?php

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\PurchaseIdentityRecipient;
use App\Models\PurchaseResult;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Support\Enums\UserType;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Purchases\RecipientType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../Support/Purchases/helpers.php';

/*
 * Phase 11 CP3 on real MariaDB: parallel HTTP submissions of one sealed Buy
 * NIN/BVN confirmation (separate processes, each through the HTTP kernel)
 * make one purchase, one debit and one provider call, and every submission
 * is sent to that purchase's result page by reference. Numbers are generated
 * when the tests run; the test-only FakeProvider returns outcomes and neutral
 * generated fixture result fields.
 * Run with: php artisan test -c phpunit.concurrency.xml
 */

beforeEach(function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Concurrency tests need MariaDB: run with -c phpunit.concurrency.xml.');
    }
    expect(DB::connection()->getDatabaseName())->toBe('nadabo_concurrency_test');
    Artisan::call('migrate:fresh', ['--force' => true]);
    puxDrivers();
    FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn'];
});

function ibcNumber(): string
{
    return (string) random_int(10_000_000_000, 99_999_999_999);
}

/** An available fixed-price NIN or BVN plan (15,000 kobo) with an executable FakeProvider route. */
function ibcPlan(RecipientType $type): Plan
{
    $service = Service::where('slug', $type->value)->first() ?? Service::factory()->create(['name' => $type->label(), 'slug' => $type->value]);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Test product', 'code' => $type->value.'-test-'.Str::lower(Str::random(6))]);
    $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Test plan', 'code' => $product->code.'-p', 'amount_type' => 'fixed']);
    PlanPrice::factory()->create(['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => 15_000]);
    puxRoute($plan, 1, ['cost_type' => 'fixed', 'cost_kobo' => 10_000]);

    return $plan->fresh();
}

/**
 * Starts $workers identity_buy_worker.php processes at one barrier, each POSTing the same sealed confirmation $count times.
 *
 * @return list<array{status: int, location: ?string}>
 */
function ibcRace(int $workers, int $userId, string $service, int $count, string $script, int $delayMs, string $confirmation, string $number): array
{
    $barrier = sys_get_temp_dir().'/identity-buy-race-'.uniqid();
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array', 'IDENTITY_TEST_CONFIRMATION' => $confirmation];

    $pool = Process::pool(function ($pool) use ($workers, $barrier, $env, $userId, $service, $count, $script, $delayMs) {
        for ($i = 0; $i < $workers; $i++) {
            $pool->path(base_path())->env($env)->timeout(120)->command([PHP_BINARY, base_path('tests/Concurrency/identity_buy_worker.php'), $barrier,
                (string) $userId, $service, (string) $count, $script, (string) $delayMs]);
        }
    })->start();

    $deadline = microtime(true) + 60;
    while (count(glob($barrier.'.ready.*')) < $workers && microtime(true) < $deadline) {
        usleep(10_000);
    }
    expect(count(glob($barrier.'.ready.*')))->toBe($workers);
    touch($barrier);
    $results = $pool->wait();
    array_map('unlink', [$barrier, ...glob($barrier.'.ready.*')]);

    $lines = [];
    foreach ($results->collect() as $result) {
        expect($result->successful())->toBeTrue('worker failed: '.$result->errorOutput())
            ->and($result->output().$result->errorOutput())->not->toContain($number);
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $decoded = json_decode($line, true);
            expect($decoded)->toBeArray()->toHaveKeys(['status', 'location']);
            $lines[] = $decoded;
        }
    }

    return $lines;
}

it('turns parallel HTTP submissions of one sealed NIN confirmation into one purchase, one debit and one provider call', function () {
    $plan = ibcPlan(RecipientType::Nin);
    $user = puxCustomer(100_000);
    $number = ibcNumber();
    $confirmation = $this->actingAs($user)->post('/buy/nin/confirm', ['plan' => $plan->id, 'identity_number' => $number])->assertOk()->viewData('confirmation');

    $results = collect(ibcRace(8, $user->id, 'nin', 3, 'succeeded', 50, $confirmation, $number));

    $purchase = Purchase::sole();
    expect($results)->toHaveCount(24)
        ->and($results->pluck('status')->unique()->values()->all())->toBe([302])
        ->and($results->pluck('location')->unique()->values()->all())->toBe(['/purchases/'.$purchase->reference])
        ->and($purchase->user_id)->toBe($user->id)
        ->and($purchase->recipient_type)->toBe(RecipientType::Nin)
        ->and($purchase->recipient)->toBeNull()
        ->and($purchase->status)->toBe(PurchaseStatus::Successful)
        ->and(PurchaseIdentityRecipient::count())->toBe(1)
        ->and(PurchaseIdentityRecipient::sole()->number(RecipientType::Nin))->toBe($number)
        ->and(Transaction::where('type', 'purchase')->count())->toBe(1)
        ->and(PurchaseAttempt::count())->toBe(1)
        ->and(PurchaseResult::count())->toBe(1)
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000)
        ->and(json_encode(DB::table('purchases')->get()))->not->toContain($number);

    $wallet = Wallet::where('user_id', $user->id)->sole();
    $running = 0;
    foreach (WalletLedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')->get() as $entry) {
        $running += $entry->signedKobo();
        expect($entry->balance_after_kobo)->toBe($running);
    }
    expect($running)->toBe(85_000)
        ->and(Artisan::call('wallet:verify'))->toBe(0)
        ->and(Artisan::call('purchases:verify'))->toBe(0, Artisan::output());
});

it('refuses parallel HTTP submissions of a confirmation sealed for another customer, buying nothing', function () {
    $plan = ibcPlan(RecipientType::Bvn);
    $owner = puxCustomer(100_000);
    $other = puxCustomer(100_000);
    $number = ibcNumber();
    $confirmation = $this->actingAs($owner)->post('/buy/bvn/confirm', ['plan' => $plan->id, 'identity_number' => $number])->assertOk()->viewData('confirmation');

    $results = collect(ibcRace(4, $other->id, 'bvn', 2, 'succeeded', 0, $confirmation, $number));

    expect($results)->toHaveCount(8)
        ->and($results->pluck('location')->unique()->values()->all())->toBe(['/buy/bvn'])
        ->and(Purchase::count())->toBe(0)
        ->and(Transaction::where('type', 'purchase')->count())->toBe(0)
        ->and(Wallet::where('user_id', $owner->id)->sole()->balance_kobo)->toBe(100_000)
        ->and(Wallet::where('user_id', $other->id)->sole()->balance_kobo)->toBe(100_000);
});
