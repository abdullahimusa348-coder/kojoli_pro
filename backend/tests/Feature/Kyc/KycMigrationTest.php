<?php

use App\Actions\Admin\Kyc\SaveKycRequirement;
use App\Models\User;
use App\Support\Enums\SystemRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 KYC migrations on SQLite: a clean rollback while empty and a migrate that restores the same schema, and a refusal
 * before any change while any KYC table holds a row. CP2a's migrations run after the CP1 tables, so the rollbacks take every KYC
 * migration (KYC_MIGRATIONS): the newest one checks every KYC table first, so a refused rollback changes nothing.
 */

/** The seven KYC migration files, by path: the only files the KYC rollbacks below may run. */
function kycMigrationPaths(): array
{
    return array_map(fn (string $name) => database_path('migrations/'.$name.'.php'), KYC_MIGRATIONS);
}

beforeEach(fn () => kycSeed());

it('rolls back the whole KYC set when it is empty, and migrates it again', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Rolling back drops KYC tables and triggers, and MariaDB commits that DDL at once, so this test runs on SQLite only.');
    }
    DB::table('kyc_requirements')->delete();

    Artisan::call('migrate:rollback', ['--step' => count(KYC_MIGRATIONS), '--path' => kycMigrationPaths(), '--realpath' => true]);

    expect(Schema::hasTable('kyc_requirements'))->toBeFalse()
        ->and(Schema::hasTable('kyc_requirement_changes'))->toBeFalse()
        ->and(Schema::hasTable('kyc_profiles'))->toBeFalse()
        ->and(Schema::hasTable('kyc_submissions'))->toBeFalse()
        ->and(Schema::hasTable('kyc_submission_requirements'))->toBeFalse()
        ->and(Schema::hasTable('kyc_submission_identities'))->toBeFalse()
        ->and(Schema::hasTable('kyc_reviews'))->toBeFalse()
        ->and(Schema::hasTable('kyc_open_submissions'))->toBeFalse()
        ->and(Schema::hasTable('kyc_documents'))->toBeFalse()
        ->and(Schema::hasTable('kyc_audit_events'))->toBeFalse()
        ->and(Permission::whereIn('name', KYC_PERMISSION_NAMES)->count())->toBe(0)
        ->and(DB::table('migrations')->whereIn('migration', KYC_MIGRATIONS)->count())->toBe(0);

    Artisan::call('migrate');

    expect(Schema::hasTable('kyc_requirements'))->toBeTrue()
        ->and(Schema::hasColumn('kyc_requirement_changes', 'old_state'))->toBeTrue()
        ->and(Schema::hasColumn('kyc_submissions', 'form_key'))->toBeTrue()
        ->and(Schema::hasTable('kyc_audit_events'))->toBeTrue()
        ->and(Permission::whereIn('name', KYC_PERMISSION_NAMES)->count())->toBe(8)
        ->and(DB::table('migrations')->whereIn('migration', KYC_MIGRATIONS)->count())->toBe(count(KYC_MIGRATIONS));
});

it('refuses to roll back while a KYC table holds a row, changing nothing', function (string $table, Closure $fill) {
    $fill();
    $migrations = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
    $message = "Refusing to roll back: {$table} holds KYC configuration or records, which would be lost. Nothing was changed.";

    expect(fn () => (require database_path('migrations/'.KYC_TABLES_MIGRATION.'.php'))->down())->toThrow(RuntimeException::class, $message)
        ->and(fn () => Artisan::call('migrate:rollback', ['--step' => count(KYC_MIGRATIONS), '--path' => kycMigrationPaths(), '--realpath' => true]))->toThrow(RuntimeException::class, $message)
        ->and(Schema::hasTable('kyc_requirements'))->toBeTrue()
        ->and(Permission::whereIn('name', KYC_PERMISSION_NAMES)->count())->toBe(8)
        ->and(DB::table('migrations')->orderBy('id')->pluck('migration')->all())->toBe($migrations);
})->with([
    'a requirement' => ['kyc_requirements', fn () => null],
    'a requirement change' => ['kyc_requirement_changes', fn () => app(SaveKycRequirement::class)->handle(
        kycRequirement('phone'), 'Phone number', null, true, ['subscriber'], 'A change that must not be lost',
        kycStaff(), SaveKycRequirement::fingerprint(kycRequirement('phone')))],
    'a KYC status' => ['kyc_profiles', fn () => DB::table('kyc_profiles')->insert([
        'user_id' => User::factory()->create()->id, 'status' => 'not_started', 'created_at' => now(), 'updated_at' => now()])],
    'a submission' => ['kyc_submissions', fn () => DB::table('kyc_submissions')->insert([
        'reference' => 'KYC-'.strtoupper((string) Str::ulid()), 'user_id' => User::factory()->create()->id,
        'kind' => 'initial', 'user_type' => 'subscriber', 'form_key' => (string) Str::uuid(),
        'submitted_at' => now(), 'created_at' => now()])],
]);

it('keeps the roles it reads for the permissions migration', function () {
    expect(SystemRole::values())->toBe(['super-admin', 'manager', 'support', 'finance', 'viewer']);
});
