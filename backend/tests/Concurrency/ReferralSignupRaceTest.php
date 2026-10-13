<?php

use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Referrals\ReferralCodeIssuer;
use App\Services\Referrals\SignupReferrer;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/*
 * Phase 12 CP3 on real MariaDB: separate PHP processes visiting the Referral
 * page and signing up through the HTTP kernel at the same moment. Parallel
 * first visits give one code and one Main Wallet; parallel signups with one
 * code are each linked once; and a signup racing the code's owner being
 * disabled never leaves a link made after the disable committed: either the
 * signup holds the owner's row (shared lock) and the disable waits for it, or
 * the disable came first and the signup is refused with the neutral message.
 * Run with: php artisan test -c phpunit.concurrency.xml
 */

beforeEach(function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Concurrency tests need MariaDB: run with -c phpunit.concurrency.xml.');
    }
    expect(DB::connection()->getDatabaseName())->toBe('nadabo_concurrency_test');
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/**
 * Starts the workers and returns their JSON lines. A worker starts when its start file exists: "run" (all at once, once
 * every worker is ready) or "locked" (once a worker has taken its lock). $during runs while they work, with the run path.
 *
 * @param  list<array{0: string, 1: string, 2: int, 3: string, 4: int}>  $workers  [start, mode, id, value, delayMs]
 * @return list<array<string, mixed>>
 */
function rsrRace(array $workers, ?Closure $during = null): array
{
    $run = sys_get_temp_dir().'/referral-race-'.uniqid();
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'array'];

    $pool = Process::pool(function ($pool) use ($workers, $run, $env) {
        foreach ($workers as $w) {
            $pool->path(base_path())->env($env)->timeout(120)->command([PHP_BINARY, base_path('tests/Concurrency/referral_worker.php'), $run,
                $w[0] === 'locked' ? $run.'.locked' : $run, $w[1], (string) $w[2], $w[3], (string) $w[4]]);
        }
    })->start();

    try {
        $deadline = microtime(true) + 60;
        while (count(glob($run.'.ready.*')) < count($workers) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        expect(count(glob($run.'.ready.*')))->toBe(count($workers));
        touch($run);
        if ($during !== null) {
            $during($run);
        }
        $results = $pool->wait();
    } finally {
        array_map('unlink', array_filter([$run, $run.'.locked', $run.'.release', ...glob($run.'.ready.*')], 'file_exists'));
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

/** A customer with their referral code: [owner, code]. */
function rsrOwner(): array
{
    $owner = User::factory()->create();

    return [$owner, app(ReferralCodeIssuer::class)->codeFor($owner)->code];
}

it('gives one code and one Main Wallet to six simultaneous first visits', function () {
    $customer = User::factory()->create();

    $results = collect(rsrRace(array_fill(0, 6, ['run', 'visit', $customer->id, '-', 200])));

    $code = ReferralCode::where('user_id', $customer->id)->sole()->code;
    expect($results)->toHaveCount(6)
        ->and($results->pluck('result')->unique()->values()->all())->toBe(['ok'])
        ->and($results->pluck('code')->unique()->values()->all())->toBe([$code])
        ->and(Wallet::where('user_id', $customer->id)->count())->toBe(1)
        ->and(ReferralCode::count())->toBe(1);
});

it('links each of six simultaneous signups with one code exactly once', function () {
    [$owner, $code] = rsrOwner();

    $results = collect(rsrRace(array_map(fn (int $i) => ['run', 'signup', $i, strtolower($code), 300], range(1, 6))));

    $applicants = User::where('email', 'like', 'applicant%')->pluck('id');
    expect($results)->toHaveCount(6)
        ->and($results->pluck('result')->unique()->values()->all())->toBe(['registered'])
        ->and($applicants)->toHaveCount(6)
        ->and(Referral::count())->toBe(6)
        ->and(Referral::pluck('referrer_id')->unique()->values()->all())->toBe([$owner->id])
        ->and(Referral::pluck('referred_user_id')->sort()->values()->all())->toBe($applicants->sort()->values()->all());
});

it('refuses signups waiting on the owner being disabled: once the disable commits, no link is made', function () {
    [$owner, $code] = rsrOwner();
    $workers = [['run', 'hold-disable', $owner->id, '-', 0], ...array_map(fn (int $i) => ['locked', 'signup', $i, $code, 0], range(1, 4))];

    $results = collect(rsrRace($workers, function (string $run) {
        $deadline = microtime(true) + 60;
        while (! file_exists($run.'.locked') && microtime(true) < $deadline) {
            usleep(10_000);
        }
        usleep(1_500_000); // the signups reach the owner's row and wait on it
        touch($run.'.release');
    }));

    expect($results->firstWhere('result', 'disabled'))->not->toBeNull()
        ->and($results->where('result', 'refused')->pluck('message')->all())->toBe(array_fill(0, 4, SignupReferrer::INVALID))
        ->and(User::where('email', 'like', 'applicant%')->count())->toBe(0)
        ->and(Referral::count())->toBe(0)
        ->and($owner->fresh()->status)->toBe(UserStatus::Disabled);
});

it('makes a disable wait for a signup that holds the owner\'s row, so no link appears after the disable commits', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $staff = SystemUser::factory()->withRole(SystemRole::SuperAdmin)->create();
    [$owner, $code] = rsrOwner();

    $results = collect(rsrRace([['run', 'signup', 1, $code, 1_500], ['locked', 'disable', $owner->id, (string) $staff->id, 0]]));

    $link = Referral::sole();
    expect($results->firstWhere('result', 'registered'))->not->toBeNull()
        ->and($link->referrer_id)->toBe($owner->id)
        // The disable committed only after the link: it already saw the link when it finished.
        ->and($results->firstWhere('result', 'disabled')['max_referral_id'])->toBe($link->id)
        ->and($owner->fresh()->status)->toBe(UserStatus::Disabled);
});
