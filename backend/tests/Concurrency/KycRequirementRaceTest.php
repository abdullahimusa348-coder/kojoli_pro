<?php

use App\Actions\Admin\Kyc\SaveKycRequirement;
use App\Models\KycRequirement;
use App\Models\KycRequirementChange;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\Enums\SystemRole;
use Database\Seeders\KycRequirementsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/Kyc/helpers.php';

/*
 * Phase 13 CP1 on real MariaDB: staff saving one KYC requirement at the same moment, as separate PHP processes submitting the
 * edit form through the HTTP kernel. Saves of one requirement queue on its row: exactly one wins, every other form is refused
 * with the reload message before writing anything, and exactly one history row is written per real change. The CHECK rules
 * that repeat the model guards are checked against the real database, past the model guards.
 * Run with: php artisan test -c phpunit.concurrency.xml
 */

beforeEach(function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Concurrency tests need MariaDB: run with -c phpunit.concurrency.xml.');
    }
    expect(DB::connection()->getDatabaseName())->toBe('nadabo_concurrency_test');
    Artisan::call('migrate:fresh', ['--force' => true]);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(KycRequirementsSeeder::class);
    $this->staff = SystemUser::factory()->withRole(SystemRole::SuperAdmin)->create();
});

/**
 * Starts the workers together and returns their JSON lines. While they run, $during gets the barrier path.
 *
 * @param  list<array{0: string, 1: int, 2: string, 3: array<string, mixed>, 4: int}>  $workers  [mode, staff, key, form, delayMs]
 * @return list<array<string, mixed>>
 */
function kycRace(array $workers, ?Closure $during = null): array
{
    $barrier = sys_get_temp_dir().'/kyc-requirement-race-'.uniqid();
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'array'];

    $pool = Process::pool(function ($pool) use ($workers, $barrier, $env) {
        foreach ($workers as $w) {
            $pool->path(base_path())->env($env)->timeout(120)->command([PHP_BINARY, base_path('tests/Concurrency/kyc_requirement_worker.php'), $barrier,
                $w[0], (string) $w[1], $w[2], json_encode($w[3], JSON_THROW_ON_ERROR), (string) $w[4]]);
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

/** The edit form values for a save of the phone requirement, with a fingerprint taken now. */
function kycRaceForm(string $label, string $reason, string $fingerprint): array
{
    return ['label' => $label, 'description' => '', 'enabled' => '1', 'user_types' => ['subscriber'], 'reason' => $reason,
        'confirm' => '1', 'fingerprint' => $fingerprint];
}

it('lets exactly one of six simultaneous edits of one requirement win and tells the others to reload', function () {
    $requirement = KycRequirement::where('key', 'phone')->sole();
    $fingerprint = SaveKycRequirement::fingerprint($requirement);

    $results = collect(kycRace(array_map(fn (int $i) => ['submit', $this->staff->id, 'phone',
        kycRaceForm("Phone {$i}", "Concurrent change number {$i}", $fingerprint), 150], range(1, 6))));

    $winner = $results->firstWhere('result', 'saved');
    $saved = KycRequirement::where('key', 'phone')->sole();
    expect($results)->toHaveCount(6)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($results->where('result', 'saved'))->toHaveCount(1)
        ->and($results->where('result', 'refused'))->toHaveCount(5)
        ->and($results->where('result', 'refused')->pluck('message')->unique()->values()->all())->toBe([SaveKycRequirement::STALE])
        ->and($results->whereNotNull('wrote')->pluck('reason')->all())->toBe([$winner['reason']]) // the others queued behind it and wrote nothing
        ->and([$saved->label, $saved->is_enabled, $saved->user_types, $saved->updated_by])->toBe([$winner['label'], true, ['subscriber'], $this->staff->id])
        ->and(KycRequirementChange::count())->toBe(1)
        ->and(KycRequirementChange::sole()->reason)->toBe($winner['reason'])
        ->and(KycRequirementChange::sole()->changed_by)->toBe($this->staff->id)
        ->and(KycRequirementChange::sole()->new_state)->toBe($saved->snapshot());
});

it('lets one of two simultaneous edits from the same form win and refuses the other', function () {
    $requirement = KycRequirement::where('key', 'bvn')->sole();
    $fingerprint = SaveKycRequirement::fingerprint($requirement);

    $results = collect(kycRace([
        ['submit', $this->staff->id, 'bvn', kycRaceForm('BVN', 'First edit from the shared form', $fingerprint), 0],
        ['submit', $this->staff->id, 'bvn', kycRaceForm('BVN (renamed)', 'Second edit from the same stale form', $fingerprint), 0],
    ]));

    expect($results->where('result', 'saved'))->toHaveCount(1)
        ->and($results->where('result', 'refused'))->toHaveCount(1)
        ->and(KycRequirementChange::count())->toBe(1);
});

it('refuses with the reload message and writes nothing when another save holds the lock past every retry', function (bool $enabled) {
    if ($enabled) {
        app(SaveKycRequirement::class)->handle(KycRequirement::where('key', 'nin')->sole(), 'NIN', null, true, ['subscriber'],
            'Starting setting for the lock test', $this->staff, SaveKycRequirement::fingerprint(KycRequirement::where('key', 'nin')->sole()));
    }
    $before = [KycRequirement::all()->toArray(), KycRequirementChange::all()->toArray()];
    $fingerprint = SaveKycRequirement::fingerprint(KycRequirement::where('key', 'nin')->sole());
    $refused = null;

    $results = kycRace([['hold', $this->staff->id, 'nin', [], 0]], function (string $barrier) use ($fingerprint, &$refused) {
        $deadline = microtime(true) + 60;
        while (! file_exists($barrier.'.locked') && microtime(true) < $deadline) {
            usleep(10_000);
        }
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1'); // each of the 3 attempts gives up after 1 second
        try {
            app(SaveKycRequirement::class)->handle(KycRequirement::where('key', 'nin')->sole(), 'NIN (blocked)', null, true, ['vendor'],
                'Blocked by the lock holder', $this->staff, $fingerprint);
        } catch (ValidationException $e) {
            $refused = $e->errors();
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
            touch($barrier.'.release');
        }
    });

    expect($results)->toBe([['result' => 'released']])
        ->and($refused)->toBe(['requirement' => [SaveKycRequirement::STALE]])
        ->and([KycRequirement::all()->toArray(), KycRequirementChange::all()->toArray()])->toBe($before);
})->with(['before any change' => false, 'after an earlier change' => true]);

it('keeps the database rules that repeat the model guards, on the real MariaDB schema', function () {
    $checks = DB::table('information_schema.CHECK_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
        ->whereIn('TABLE_NAME', ['kyc_requirements', 'kyc_requirement_changes', 'kyc_profiles', 'kyc_submissions'])
        ->pluck('CONSTRAINT_NAME')->sort()->values()->all();

    // MariaDB also keeps the JSON-validity checks Laravel adds to each JSON column, named after the column.
    expect($checks)->toBe(collect(['kyc_profiles_status_rule', 'kyc_requirement_changes_reason_length', 'kyc_requirements_label_length',
        'kyc_requirements_type_rule', 'kyc_submissions_reference_rule', 'new_state', 'old_state', 'purposes', 'user_types'])->sort()->values()->all());
});

/** The database refuses the insert, naming the constraint, even though it bypasses the model guards. */
function kycRefused(string $constraint, Closure $insert): void
{
    expect($insert)->toThrow(QueryException::class, "CONSTRAINT `{$constraint}` failed");
}

/** A valid requirement row for the query builder, with overrides. */
function kycRawRequirement(array $overrides = []): array
{
    return $overrides + ['key' => 'raw-'.Str::lower(Str::random(8)), 'type' => 'document', 'label' => 'Raw row', 'description' => null,
        'is_enabled' => false, 'user_types' => '[]', 'purposes' => '[]', 'position' => 500, 'created_at' => now(), 'updated_at' => now()];
}

it('refuses rows that break a database rule, even when the model guards are bypassed', function () {
    kycRefused('kyc_requirements_type_rule', fn () => DB::table('kyc_requirements')->insert(kycRawRequirement(['type' => 'bank'])));
    kycRefused('kyc_requirements_label_length', fn () => DB::table('kyc_requirements')->insert(kycRawRequirement(['label' => 'A'])));

    $requirement = KycRequirement::where('key', 'phone')->sole();
    $change = ['kyc_requirement_id' => $requirement->id, 'old_state' => '{}', 'new_state' => '{}', 'changed_by' => $this->staff->id,
        'created_at' => now()];
    kycRefused('kyc_requirement_changes_reason_length', fn () => DB::table('kyc_requirement_changes')->insert($change + ['reason' => str_repeat('r', 9)]));

    $customer = User::factory()->create();
    kycRefused('kyc_profiles_status_rule', fn () => DB::table('kyc_profiles')->insert(['user_id' => $customer->id, 'status' => 'bogus',
        'created_at' => now(), 'updated_at' => now()]));
    kycRefused('kyc_submissions_reference_rule', fn () => DB::table('kyc_submissions')->insert(['reference' => 'PUR-'.strtoupper((string) Str::ulid()),
        'user_id' => $customer->id, 'submitted_at' => now(), 'created_at' => now()]));
});

it('caps each name and reason at the size of its column, which the database enforces as well', function () {
    $requirement = KycRequirement::where('key', 'phone')->sole();
    $change = ['kyc_requirement_id' => $requirement->id, 'old_state' => '{}', 'new_state' => '{}', 'changed_by' => $this->staff->id,
        'created_at' => now()];

    expect(fn () => DB::table('kyc_requirements')->insert(kycRawRequirement(['label' => str_repeat('L', 121)])))->toThrow(QueryException::class, 'Data too long')
        ->and(fn () => DB::table('kyc_requirement_changes')->insert($change + ['reason' => str_repeat('r', 501)]))->toThrow(QueryException::class, 'Data too long');
});

it('accepts the smallest and largest values the rules allow', function () {
    DB::table('kyc_requirements')->insert(kycRawRequirement(['label' => 'AB']));
    $requirement = KycRequirement::where('key', 'phone')->sole();
    $change = ['kyc_requirement_id' => $requirement->id, 'old_state' => '{}', 'new_state' => '{}', 'changed_by' => $this->staff->id,
        'created_at' => now()];
    DB::table('kyc_requirement_changes')->insert($change + ['reason' => str_repeat('r', 10)]);
    DB::table('kyc_requirement_changes')->insert($change + ['reason' => str_repeat('r', 500)]);

    $customer = User::factory()->create();
    DB::table('kyc_profiles')->insert(['user_id' => $customer->id, 'status' => 'more_info_requested', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('kyc_submissions')->insert(['reference' => 'KYC-'.strtoupper((string) Str::ulid()), 'user_id' => $customer->id,
        'submitted_at' => now(), 'created_at' => now()]);

    expect(DB::table('kyc_requirements')->where('label', 'AB')->exists())->toBeTrue()
        ->and(KycRequirementChange::count())->toBe(2)
        ->and(DB::table('kyc_profiles')->where('user_id', $customer->id)->value('status'))->toBe('more_info_requested')
        ->and(DB::table('kyc_submissions')->where('user_id', $customer->id)->count())->toBe(1);
});
