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
 * Phase 13 CP1 migrations on SQLite: a clean rollback while empty and a migrate that restores the same schema, and a refusal
 * before any change while any KYC table holds a row. The permissions migration is older than the tables migration, so a
 * refused rollback of the tables stops before the permissions are touched.
 */

beforeEach(fn () => kycSeed());

it('rolls back the whole KYC configuration when it is empty, and migrates it again', function () {
    DB::table('kyc_requirements')->delete();

    Artisan::call('migrate:rollback', ['--step' => 2]);

    expect(Schema::hasTable('kyc_requirements'))->toBeFalse()
        ->and(Schema::hasTable('kyc_requirement_changes'))->toBeFalse()
        ->and(Schema::hasTable('kyc_profiles'))->toBeFalse()
        ->and(Schema::hasTable('kyc_submissions'))->toBeFalse()
        ->and(Permission::whereIn('name', KYC_PERMISSION_NAMES)->count())->toBe(0)
        ->and(DB::table('migrations')->whereIn('migration', [KYC_PERMISSIONS_MIGRATION, KYC_TABLES_MIGRATION])->count())->toBe(0);

    Artisan::call('migrate');

    expect(Schema::hasTable('kyc_requirements'))->toBeTrue()
        ->and(Schema::hasColumn('kyc_requirement_changes', 'old_state'))->toBeTrue()
        ->and(Permission::whereIn('name', KYC_PERMISSION_NAMES)->count())->toBe(8)
        ->and(DB::table('migrations')->whereIn('migration', [KYC_PERMISSIONS_MIGRATION, KYC_TABLES_MIGRATION])->count())->toBe(2);
});

it('refuses to roll back while a KYC table holds a row, changing nothing', function (string $table, Closure $fill) {
    $fill();
    $migrations = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
    $message = "Refusing to roll back: {$table} holds KYC configuration or records, which would be lost. Nothing was changed.";

    expect(fn () => (require database_path('migrations/'.KYC_TABLES_MIGRATION.'.php'))->down())->toThrow(RuntimeException::class, $message)
        ->and(fn () => Artisan::call('migrate:rollback', ['--step' => 2]))->toThrow(RuntimeException::class, $message)
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
        'submitted_at' => now(), 'created_at' => now()])],
]);

it('keeps the roles it reads for the permissions migration', function () {
    expect(SystemRole::values())->toBe(['super-admin', 'manager', 'support', 'finance', 'viewer']);
});
