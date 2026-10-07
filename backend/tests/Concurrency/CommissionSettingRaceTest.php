<?php

use App\Actions\Admin\Referrals\SaveCommissionSetting;
use App\Models\CommissionSetting;
use App\Models\CommissionSettingChange;
use App\Models\Service;
use App\Models\SystemUser;
use App\Support\Enums\SystemRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

/*
 * Phase 12 CP2 (T5) on real MariaDB: staff saving referral commission rates
 * and caps at the same moment, as separate PHP processes submitting the
 * Rates & caps edit form through the HTTP kernel. Saves of one service queue
 * on that service's row: exactly one wins, every other form is refused with
 * the reload message before writing anything (never an error or a second
 * setting), and exactly one history row is written per real change.
 * Different services never hold each other up.
 * Run with: php artisan test -c phpunit.concurrency.xml
 */

beforeEach(function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Concurrency tests need MariaDB: run with -c phpunit.concurrency.xml.');
    }
    expect(DB::connection()->getDatabaseName())->toBe('nadabo_concurrency_test');
    Artisan::call('migrate:fresh', ['--force' => true]);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = Service::factory()->create(['name' => 'Data', 'slug' => 'data']);
    $this->staff = SystemUser::factory()->withRole(SystemRole::SuperAdmin)->create();
});

/**
 * Starts the workers together and returns their JSON lines. While they run, $during gets the barrier path.
 *
 * @param  list<array{0: string, 1: int, 2: string, 3: string, 4: string, 5: string, 6: int}>  $workers  [mode, staff, slug, rate, cap, fingerprint, delayMs]
 * @return list<array<string, mixed>>
 */
function csrRace(array $workers, ?Closure $during = null): array
{
    $barrier = sys_get_temp_dir().'/commission-setting-race-'.uniqid();
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'array'];

    $pool = Process::pool(function ($pool) use ($workers, $barrier, $env) {
        foreach ($workers as $w) {
            $pool->path(base_path())->env($env)->timeout(120)->command([PHP_BINARY, base_path('tests/Concurrency/commission_setting_worker.php'), $barrier,
                $w[0], (string) $w[1], $w[2], $w[3], $w[4], $w[5], (string) $w[6]]);
        }
    })->start();

    try {
        $deadline = microtime(true) + 60;
        while (count(glob($barrier.'.ready.*')) < count($workers) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        expect(count(glob($barrier.'.ready.*')))->toBe(count($workers));
        touch($barrier);
        if ($during !== null) {
            $during($barrier);
        }
        $results = $pool->wait();
    } finally {
        array_map('unlink', array_filter([$barrier, $barrier.'.locked', $barrier.'.release', ...glob($barrier.'.ready.*')], 'file_exists'));
    }

    $lines = [];
    foreach ($results->collect() as $result) {
        expect($result->successful())->toBeTrue('worker failed: '.$result->errorOutput());
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $lines[] = json_decode($line, true);
        }
    }

    return $lines;
}

it('lets exactly one of six simultaneous first saves win and tells the others to reload (T5)', function () {
    $fingerprint = SaveCommissionSetting::fingerprint($this->service);

    $results = collect(csrRace(array_map(fn (int $i) => ['submit', $this->staff->id, 'data', (string) $i, (string) (100 * $i), $fingerprint, 100], range(1, 6))));

    $winner = $results->firstWhere('result', 'saved');
    $setting = CommissionSetting::sole();
    $change = CommissionSettingChange::sole();
    expect($results)->toHaveCount(6)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($results->where('result', 'saved'))->toHaveCount(1)
        ->and($results->where('result', 'refused'))->toHaveCount(5)
        ->and($results->where('result', 'refused')->pluck('message')->unique()->values()->all())->toBe([SaveCommissionSetting::STALE])
        ->and($winner['message'])->toBe("Data saved: rate {$winner['rate']}%, cap ₦".number_format((int) $winner['cap'], 2).'.')
        ->and($results->whereNotNull('wrote')->pluck('reason')->all())->toBe([$winner['reason']]) // the others queued behind it and wrote nothing
        ->and($setting->service_id)->toBe($this->service->id)
        ->and($setting->rate_bps)->toBe(100 * (int) $winner['rate'])
        ->and($setting->cap_kobo)->toBe(100 * (int) $winner['cap'])
        ->and($setting->updated_by)->toBe($this->staff->id)
        ->and([$change->commission_setting_id, $change->service_id, $change->old_rate_bps, $change->old_cap_kobo, $change->new_rate_bps, $change->new_cap_kobo])
        ->toBe([$setting->id, $this->service->id, null, null, $setting->rate_bps, $setting->cap_kobo])
        ->and($change->reason)->toBe($winner['reason'])
        ->and($change->changed_by)->toBe($this->staff->id);
});

it('applies exactly one of six simultaneous edits made from the same form and tells the others to reload (T5)', function () {
    app(SaveCommissionSetting::class)->handle($this->service, 150, 50_000, 'Starting rate for the race', $this->staff, SaveCommissionSetting::fingerprint($this->service));
    $fingerprint = SaveCommissionSetting::fingerprint($this->service);

    $results = collect(csrRace(array_map(fn (int $i) => ['submit', $this->staff->id, 'data', (string) $i, (string) (100 * $i), $fingerprint, 200], range(1, 6))));

    $winner = $results->firstWhere('result', 'saved');
    $setting = CommissionSetting::sole();
    $last = CommissionSettingChange::latest('id')->first();
    expect($results)->toHaveCount(6)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($results->where('result', 'saved'))->toHaveCount(1)
        ->and($results->where('result', 'refused'))->toHaveCount(5)
        ->and($results->where('result', 'refused')->pluck('message')->unique()->values()->all())->toBe([SaveCommissionSetting::STALE])
        ->and($results->whereNotNull('wrote')->pluck('reason')->all())->toBe([$winner['reason']]) // the others queued behind it and wrote nothing
        ->and(CommissionSettingChange::count())->toBe(2)
        ->and([$setting->rate_bps, $setting->cap_kobo])->toBe([100 * (int) $winner['rate'], 100 * (int) $winner['cap']])
        ->and([$last->old_rate_bps, $last->old_cap_kobo, $last->new_rate_bps, $last->new_cap_kobo])->toBe([150, 50_000, $setting->rate_bps, $setting->cap_kobo])
        ->and($last->reason)->toBe($winner['reason']);
});

it('lets different services save their first rates at the same moment, each queueing only behind its own service', function () {
    $services = ['data' => $this->service];
    foreach (['airtime' => 'Airtime', 'nin' => 'NIN', 'bvn' => 'BVN', 'exam-pin' => 'Exam PIN'] as $slug => $name) {
        $services[$slug] = Service::factory()->create(['name' => $name, 'slug' => $slug]);
    }
    // Two forms per service, both opened before either was saved; every save holds its transaction open for 500 ms.
    $workers = [];
    foreach ($services as $slug => $service) {
        foreach (['1', '2'] as $rate) {
            $workers[] = ['submit', $this->staff->id, $slug, $rate, '100', SaveCommissionSetting::fingerprint($service), 500];
        }
    }

    $results = collect(csrRace($workers));

    $saved = $results->where('result', 'saved');
    $refused = $results->where('result', 'refused');
    expect($results)->toHaveCount(10)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($saved->pluck('slug')->sort()->values()->all())->toBe(['airtime', 'bvn', 'data', 'exam-pin', 'nin'])
        ->and($refused->pluck('slug')->sort()->values()->all())->toBe(['airtime', 'bvn', 'data', 'exam-pin', 'nin'])
        ->and($refused->pluck('message')->unique()->values()->all())->toBe([SaveCommissionSetting::STALE])
        ->and($refused->whereNotNull('wrote'))->toHaveCount(0)
        // The five winners were writing at the same time: none waited behind another service's 500 ms save.
        ->and($saved->max('wrote') - $saved->min('wrote'))->toBeLessThan(0.5)
        ->and(CommissionSetting::count())->toBe(5)
        ->and(CommissionSettingChange::count())->toBe(5);
    foreach ($saved as $result) {
        $setting = CommissionSetting::where('service_id', $services[$result['slug']]->id)->sole();
        $change = CommissionSettingChange::where('service_id', $setting->service_id)->sole();
        expect([$setting->rate_bps, $setting->cap_kobo, $setting->updated_by])->toBe([100 * (int) $result['rate'], 10_000, $this->staff->id])
            ->and([$change->commission_setting_id, $change->old_rate_bps, $change->old_cap_kobo, $change->new_rate_bps, $change->new_cap_kobo, $change->reason])
            ->toBe([$setting->id, null, null, $setting->rate_bps, 10_000, $result['reason']]);
    }
});

it('refuses with the reload message and writes nothing when another save holds the lock past every retry', function (bool $existing) {
    if ($existing) {
        app(SaveCommissionSetting::class)->handle($this->service, 150, 50_000, 'Starting rate for the lock test', $this->staff, SaveCommissionSetting::fingerprint($this->service));
    }
    $before = [CommissionSetting::all()->toArray(), CommissionSettingChange::all()->toArray()];
    $fingerprint = SaveCommissionSetting::fingerprint($this->service);
    $refused = null;

    $results = csrRace([['hold', $this->staff->id, 'data', '-', '-', '-', 0]], function (string $barrier) use ($fingerprint, &$refused) {
        $deadline = microtime(true) + 60;
        while (! file_exists($barrier.'.locked') && microtime(true) < $deadline) {
            usleep(10_000);
        }
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1'); // each of the 3 attempts gives up after 1 second
        try {
            app(SaveCommissionSetting::class)->handle($this->service, 300, 75_000, 'Blocked by the lock holder', $this->staff, $fingerprint);
        } catch (ValidationException $e) {
            $refused = $e->errors();
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
            touch($barrier.'.release');
        }
    });

    expect($results)->toBe([['result' => 'released']])
        ->and($refused)->toBe(['setting' => [SaveCommissionSetting::STALE]])
        ->and([CommissionSetting::all()->toArray(), CommissionSettingChange::all()->toArray()])->toBe($before);
})->with(['before the first save' => false, 'on an existing setting' => true]);
