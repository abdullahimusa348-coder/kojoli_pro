<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a history triggers on SQLite: an update and a delete trigger on each of the seven history tables; a rollback refused
 * while a history row exists, which names the first table in rollback order that holds one and changes nothing; and an empty rollback
 * that removes the triggers, which running the migration again restores. The trigger catalogue is read from sqlite_master here; the
 * MariaDB suite reads information_schema for the same triggers.
 */

beforeEach(fn () => kycSeed());

/** The KYC trigger names in the database, in name order. */
function kycHistoryTriggers(): array
{
    return DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'kyc%')->orderBy('name')->pluck('name')->all();
}

it('creates an update and a delete trigger on each of the seven history tables, and no other KYC trigger', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The trigger catalogue is read from sqlite_master, which only SQLite has, so this test runs on SQLite only.');
    }
    $expected = collect(['kyc_requirement_changes', 'kyc_submissions', 'kyc_submission_requirements', 'kyc_submission_identities',
        'kyc_reviews', 'kyc_documents', 'kyc_audit_events'])
        ->flatMap(fn (string $table) => ["{$table}_no_update", "{$table}_no_delete"])
        ->merge(['kyc_submission_requirements_no_insert_after_decision', 'kyc_submission_identities_no_insert_after_decision',
            'kyc_documents_no_insert_after_decision', 'kyc_open_submissions_no_insert_after_decision', 'kyc_reviews_release_open_slot',
            'kyc_reviews_approval_gate'])
        ->sort(SORT_STRING)->values()->all();

    expect(kycHistoryTriggers())->toBe($expected)
        ->and(count($expected))->toBe(20);
});

it('refuses to roll back while a history row exists, naming the first table in rollback order that holds one, and changes nothing', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The trigger catalogue is read from sqlite_master, which only SQLite has, so this test runs on SQLite only.');
    }
    kycEnable('bvn');
    kycEnable('nin');
    kycOpen(kycCustomer());
    $migration = require database_path('migrations/'.KYC_TRIGGERS_MIGRATION.'.php');
    $message = 'Refusing to roll back: kyc_audit_events holds KYC configuration or records, which would be lost. Nothing was changed.';
    $applied = DB::table('migrations')->orderBy('id')->pluck('migration')->all();

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, $message)
        ->and(kycHistoryTriggers())->toHaveCount(20)
        ->and(DB::table('migrations')->orderBy('id')->pluck('migration')->all())->toBe($applied);
});

it('drops the triggers when it rolls back with no rows, and restores them when it migrates again', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Dropping and creating triggers is DDL that MariaDB commits at once, so this test runs on SQLite only.');
    }
    DB::table('kyc_requirements')->delete();
    $path = 'database/migrations/'.KYC_TRIGGERS_MIGRATION.'.php';

    Artisan::call('migrate:rollback', ['--path' => $path]);
    expect(kycHistoryTriggers())->toBe([]);

    Artisan::call('migrate', ['--path' => $path]);
    expect(kycHistoryTriggers())->toHaveCount(20);
});
