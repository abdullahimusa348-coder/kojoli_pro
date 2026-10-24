<?php

use App\Models\SystemUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\ExpectationFailedException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// tests/Pest.php binds Laravel's TestCase only to the Feature and Concurrency directories, so this file binds it itself.
uses(Tests\TestCase::class);

/*
 * Phase 13 CP2a KYC migrations on a MariaDB test database (review item B1). The rollback refuses while any KYC table holds a row, and
 * changes nothing. The rollback of an empty KYC schema removes every KYC table, trigger, migration row and KYC permission. The re-migration
 * restores the same tables, the same twenty triggers and the same permission grants. The slot-release trigger and the approval gate
 * work after the re-migration, and the triggers are created under the migrating account. The trigger migration refuses, before it changes
 * anything, under a pre-provisioned account that holds SELECT on the tables its guard reads and no DELETE on kyc_open_submissions. That
 * test is skipped unless NADABO_KYC_GUARD_DB_USERNAME and NADABO_KYC_GUARD_DB_PASSWORD are both set, and neither value is ever printed.
 * Every change to the database in this file is preceded by kmgGuard(), which requires the suite marker, the mysql driver, exactly the database
 * nadabo_concurrency_test, and the pinned local MariaDB socket. kmgMariaDb() then checks the server's identity. beforeEach runs
 * kmgGuard() before each test, and each test resets the database in its own body, after its environment check where it has one.
 * Synthetic data only. Run it only with phpunit.kyc-migrations-mariadb.xml, which sets NADABO_KYC_MIGRATIONS_SUITE, the marker
 * that kmgGuard() requires. The hosted workflow (.github/workflows/kyc-mariadb.yml) and a local run both use that configuration.
 */

/** The seven KYC migration files, in apply order. */
const KMG_MIGRATIONS = [
    '2026_10_08_100000_create_kyc_permissions',
    '2026_10_08_100100_create_kyc_configuration_tables',
    '2026_10_09_100000_extend_kyc_submissions_for_snapshots',
    '2026_10_09_100100_create_kyc_submission_snapshot_tables',
    '2026_10_09_100200_create_kyc_review_and_open_submission_tables',
    '2026_10_09_100300_create_kyc_document_and_audit_tables',
    '2026_10_09_100400_create_kyc_history_immutability_triggers',
];

const KMG_TRIGGER_MIGRATION = '2026_10_09_100400_create_kyc_history_immutability_triggers';

/** The ten KYC tables, in the order the trigger migration checks them before a rollback (its TABLES constant). */
const KMG_TABLES = [
    'kyc_documents',
    'kyc_audit_events',
    'kyc_open_submissions',
    'kyc_reviews',
    'kyc_submission_identities',
    'kyc_submission_requirements',
    'kyc_submissions',
    'kyc_profiles',
    'kyc_requirement_changes',
    'kyc_requirements',
];

/** The twenty KYC triggers the trigger migration creates. */
const KMG_TRIGGERS = [
    'kyc_audit_events_no_update',
    'kyc_audit_events_no_delete',
    'kyc_documents_no_update',
    'kyc_documents_no_delete',
    'kyc_documents_no_insert_after_decision',
    'kyc_open_submissions_no_insert_after_decision',
    'kyc_requirement_changes_no_update',
    'kyc_requirement_changes_no_delete',
    'kyc_reviews_no_update',
    'kyc_reviews_no_delete',
    'kyc_reviews_release_open_slot',
    'kyc_reviews_approval_gate',
    'kyc_submission_identities_no_update',
    'kyc_submission_identities_no_delete',
    'kyc_submission_identities_no_insert_after_decision',
    'kyc_submission_requirements_no_update',
    'kyc_submission_requirements_no_delete',
    'kyc_submission_requirements_no_insert_after_decision',
    'kyc_submissions_no_update',
    'kyc_submissions_no_delete',
];

/** The eight KYC permissions the permissions migration creates. */
const KMG_PERMISSIONS = [
    'kyc.view',
    'kyc.review',
    'kyc.requirements',
    'kyc.documents',
    'virtual-accounts.view',
    'virtual-accounts.manage',
    'virtual-accounts.providers',
    'virtual-accounts.credentials',
];

/** The admin roles the permissions migration grants to, with the permissions each one receives. */
const KMG_ROLE_GRANTS = [
    'super-admin' => [
        'kyc.view', 'kyc.review', 'kyc.requirements', 'kyc.documents',
        'virtual-accounts.view', 'virtual-accounts.manage', 'virtual-accounts.providers', 'virtual-accounts.credentials',
    ],
    'manager' => ['kyc.view', 'kyc.review', 'kyc.requirements', 'kyc.documents', 'virtual-accounts.view'],
    'support' => ['kyc.view'],
    'finance' => ['virtual-accounts.view', 'virtual-accounts.manage', 'virtual-accounts.providers'],
];

/** The environment names of the restricted account. Their values are read and passed to the connection, and never printed. */
const KMG_GUARD_USERNAME_ENV = 'NADABO_KYC_GUARD_DB_USERNAME';
const KMG_GUARD_PASSWORD_ENV = 'NADABO_KYC_GUARD_DB_PASSWORD';

/** Why the restricted-account test is skipped. It names the variables and never their values. */
const KMG_GUARD_SKIP_REASON = 'Set NADABO_KYC_GUARD_DB_USERNAME and NADABO_KYC_GUARD_DB_PASSWORD to a pre-provisioned account that holds '
    .'SELECT on nadabo_concurrency_test and no DELETE on kyc_open_submissions. The restricted-account test is skipped without them.';

/** The connection the restricted account uses, configured from the default connection with that account's name and password. */
const KMG_RESTRICTED_CONNECTION = 'kmg_restricted';

/** The dedicated B1 owner: the configured username, and the account the server must report after sign-in. */
const KMG_OWNER_USER = 'nadabo_concurrency';
const KMG_OWNER_ACCOUNT = 'nadabo_concurrency@localhost';

/** The suite marker. The suite's phpunit config sets it, and kmgGuard() refuses any run without it, so this file runs only through that config. */
const KMG_SUITE_ENV = 'NADABO_KYC_MIGRATIONS_SUITE';
const KMG_SUITE_MARKER = 'kyc-migrations-mariadb';

/** Account names the restricted account must never use: the default app account, which holds every privilege, the owner, and root. */
const KMG_RESTRICTED_DENIED_NAMES = ['nadabo', 'nadabo_concurrency', 'root'];

/**
 * The reads the trigger migration's guard makes, plus the migrations table the migrator reads. The restricted account must pass all of
 * them before any change. Keep them in step with assertDefinerCanRun() in the trigger migration.
 */
const KMG_RESTRICTED_READS = [
    'SELECT id FROM kyc_submissions WHERE 1 = 0',
    'SELECT kyc_submission_id, decision FROM kyc_reviews WHERE 1 = 0',
    'SELECT kyc_submission_id, requirement_key, requirement_type FROM kyc_submission_requirements WHERE 1 = 0',
    'SELECT kyc_submission_id, requirement_key FROM kyc_submission_identities WHERE 1 = 0',
    'SELECT kyc_submission_id FROM kyc_documents WHERE 1 = 0',
    'SELECT kyc_submission_id FROM kyc_open_submissions WHERE 1 = 0',
    'SELECT migration FROM migrations WHERE 1 = 0',
];

/** The delete the trigger migration's guard makes. The restricted account must be denied it (MariaDB error 1142). */
const KMG_RESTRICTED_DELETE = 'DELETE FROM kyc_open_submissions WHERE kyc_submission_id = -1';

/**
 * The guard before every change: the connection uses the mysql driver, which PDO also uses for MariaDB, its database is exactly
 * nadabo_concurrency_test, and it uses the Unix socket pinned in DB_SOCKET. A non-empty socket makes PDO ignore the host and port, so no
 * TCP connection is possible. Every connection except the restricted one must be the owner account: its configured username
 * is checked before any query, and the account the server accepted is checked after connecting. kmgMariaDb() then checks the server's
 * identity. Every check here is a read.
 */
function kmgGuard(?string $connection = null): void
{
    if (kmgGuardSetting(KMG_SUITE_ENV) !== KMG_SUITE_MARKER) {
        throw new RuntimeException('kmgGuard refuses this run: NADABO_KYC_MIGRATIONS_SUITE is not the suite marker, so this run did not start from the suite config. Nothing was changed.');
    }

    expect(in_array(DB::connection($connection)->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    expect(DB::connection($connection)->getDatabaseName())->toBe('nadabo_concurrency_test');

    $socket = (string) (DB::connection($connection)->getConfig()['unix_socket'] ?? '');
    if ($socket === '' || $socket !== kmgGuardSetting('DB_SOCKET') || ! file_exists($socket) || filetype($socket) !== 'socket') {
        throw new RuntimeException('kmgGuard refuses this connection: it must use the verified local Unix socket in DB_SOCKET. Placeholder values are refused.');
    }

    if ($connection !== KMG_RESTRICTED_CONNECTION && DB::connection($connection)->getConfig('username') !== KMG_OWNER_USER) {
        throw new RuntimeException('kmgGuard refuses this connection: DB_USERNAME must be nadabo_concurrency, the B1 test owner.');
    }

    kmgMariaDb($connection);

    if ($connection !== KMG_RESTRICTED_CONNECTION) {
        $login = (string) DB::connection($connection)->selectOne('SELECT CURRENT_USER() AS account')->account;
        if ($login !== KMG_OWNER_ACCOUNT) {
            throw new RuntimeException('kmgGuard refuses this connection: it must sign in as nadabo_concurrency@localhost, the B1 test owner.');
        }
    }
}

/**
 * Refuses a connection that is not the verified local MariaDB. The server must report MariaDB, its current database must be
 * nadabo_concurrency_test, and its socket, port and hostname must match the pinned socket and port and this machine's hostname. A
 * forwarded socket or a remote server reports something else. The statement is a read, so it runs before any reset or change.
 */
function kmgMariaDb(?string $connection = null): void
{
    $server = DB::connection($connection)->selectOne(
        'SELECT VERSION() AS version_text, DATABASE() AS current_db, @@socket AS socket_path, @@port AS port_number, @@hostname AS host_name'
    );

    if (! str_contains((string) $server->version_text, 'MariaDB')
        || $server->current_db !== 'nadabo_concurrency_test'
        || $server->socket_path !== kmgGuardSetting('DB_SOCKET')
        || (int) $server->port_number !== (int) kmgGuardSetting('DB_PORT')
        || $server->host_name !== gethostname()) {
        throw new RuntimeException('kmgMariaDb refuses this connection: its server is not the verified local MariaDB, or its database is not nadabo_concurrency_test. Nothing is migrated or reset.');
    }
}

/** A list of names sorted as strings, so two lists of names compare as sets. */
function kmgSorted(array $names): array
{
    sort($names, SORT_STRING);

    return $names;
}

/** The migration files by absolute path: the only files these tests roll back or migrate. */
function kmgPaths(array $names = KMG_MIGRATIONS): array
{
    return array_map(fn (string $name) => database_path('migrations/'.$name.'.php'), $names);
}

/**
 * Drops every table in the test database and migrates it again. kmgGuard() runs first, so the MariaDB check comes before the reset.
 * A non-zero exit code fails the test.
 */
function kmgFresh(): void
{
    kmgGuard();
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
}

/**
 * Rolls back the seven KYC migrations, newest first. A step counts every migration row, and migrate:rollback skips a row whose file is
 * not on the path, so the seven newest rows must be exactly the KYC migrations. Any other row among them fails this check, before any change.
 */
function kmgRollBackAll(): int
{
    kmgGuard();
    $steps = count(KMG_MIGRATIONS);
    expect(kmgSorted(kmgNewestMigrations($steps)))->toBe(kmgSorted(KMG_MIGRATIONS));

    return Artisan::call('migrate:rollback', ['--step' => $steps, '--path' => kmgPaths(), '--realpath' => true, '--force' => true]);
}

/**
 * Rolls back the trigger migration alone. A step counts every migration row, so the trigger migration must be the newest row, and this
 * checks that before any change.
 */
function kmgRollBackTriggers(): int
{
    kmgGuard();
    expect(kmgNewestMigrations(1))->toBe([KMG_TRIGGER_MIGRATION]);

    return Artisan::call('migrate:rollback', ['--step' => 1, '--path' => kmgPaths([KMG_TRIGGER_MIGRATION]), '--realpath' => true, '--force' => true]);
}

/**
 * The names of the newest migration rows, in the order migrate:rollback --step takes them: batch 1 or later, batch descending, then
 * name descending. This is the selection Laravel's getMigrations() makes.
 */
function kmgNewestMigrations(int $count): array
{
    return DB::table('migrations')
        ->where('batch', '>=', 1)
        ->orderByDesc('batch')
        ->orderByDesc('migration')
        ->limit($count)
        ->pluck('migration')
        ->all();
}

function kmgMigrateAll(): int
{
    kmgGuard();

    return Artisan::call('migrate', ['--force' => true]);
}

/** Creates the four admin roles that the permissions migration grants to. */
function kmgRoles(): void
{
    kmgGuard();
    foreach (array_keys(KMG_ROLE_GRANTS) as $name) {
        Role::findOrCreate($name, 'admin');
    }
}

/**
 * Creates the roles, then rolls back and migrates again, so the permissions migration grants them. The grants are asserted here,
 * so a later comparison cannot pass only because nothing was granted.
 */
function kmgWithPermissions(): void
{
    kmgRoles();
    expect(kmgRollBackAll())->toBe(0);
    expect(kmgMigrateAll())->toBe(0);
    expect(kmgPermissionNames())->toBe(kmgSorted(KMG_PERMISSIONS));
    expect(kmgGrants())->toBe(kmgExpectedGrants());
}

/** The KYC permissions that exist, sorted. */
function kmgPermissionNames(): array
{
    return kmgSorted(Permission::query()->where('guard_name', 'admin')->whereIn('name', KMG_PERMISSIONS)->pluck('name')->all());
}

/** The permissions each KYC role holds, by role name, with each list sorted. */
function kmgGrants(): array
{
    $grants = [];
    foreach (array_keys(KMG_ROLE_GRANTS) as $name) {
        $role = Role::query()->where('guard_name', 'admin')->where('name', $name)->first();
        $grants[$name] = $role === null ? [] : kmgSorted($role->permissions()->pluck('name')->all());
    }

    return $grants;
}

/** The grants the permissions migration makes, in the same shape kmgGrants() returns. */
function kmgExpectedGrants(): array
{
    return array_map(fn (array $names) => kmgSorted($names), KMG_ROLE_GRANTS);
}

/**
 * The state a rollback and a re-migration must leave as it was: each KYC table's definition, the trigger definitions and definers,
 * the other migration rows with their batches, the other tables, and the KYC permissions and grants. The KYC migration rows keep
 * their names only, because a re-migration gives them a new batch.
 */
function kmgState(): array
{
    kmgGuard();
    $database = DB::connection()->getDatabaseName();

    $definitions = [];
    foreach (KMG_TABLES as $table) {
        if (Schema::hasTable($table)) {
            $ddl = (string) DB::selectOne('SHOW CREATE TABLE `'.$table.'`')->{'Create Table'};
            $definitions[$table] = preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl);
        }
    }

    return [
        'definitions' => $definitions,
        'triggers' => DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', $database)
            ->where('TRIGGER_NAME', 'like', 'kyc%')
            ->orderBy('TRIGGER_NAME')
            ->get(['TRIGGER_NAME', 'ACTION_TIMING', 'EVENT_MANIPULATION', 'EVENT_OBJECT_TABLE', 'DEFINER', 'ACTION_STATEMENT'])
            ->map(fn ($row) => (array) $row)
            ->all(),
        'kyc_migrations' => kmgKycMigrations(),
        'migrations' => DB::table('migrations')->whereNotIn('migration', KMG_MIGRATIONS)->orderBy('migration')->pluck('batch', 'migration')->all(),
        'tables' => collect(Schema::getTables($database))->pluck('name')
            ->reject(fn (string $name) => in_array($name, KMG_TABLES, true))
            ->sort()->values()->all(),
        'permissions' => kmgPermissionNames(),
        'grants' => kmgGrants(),
    ];
}

/** The KYC migration rows that are recorded, sorted. */
function kmgKycMigrations(): array
{
    return kmgSorted(DB::table('migrations')->whereIn('migration', KMG_MIGRATIONS)->pluck('migration')->all());
}

/** The KYC tables that exist, in KMG_TABLES order. */
function kmgPresentTables(): array
{
    return array_values(array_filter(KMG_TABLES, fn (string $table) => Schema::hasTable($table)));
}

/** The KYC trigger names on the current database, sorted. */
function kmgTriggers(): array
{
    return kmgSorted(DB::table('information_schema.TRIGGERS')
        ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
        ->where('TRIGGER_NAME', 'like', 'kyc%')
        ->pluck('TRIGGER_NAME')
        ->all());
}

/** The row count of each KYC table that exists. */
function kmgCounts(): array
{
    $counts = [];
    foreach (kmgPresentTables() as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

/** Writes one row through the query builder, past the model guards, so the database itself is what is tested. */
function kmgRow(string $table, array $row): int
{
    kmgGuard();

    return (int) DB::table($table)->insertGetId($row);
}

function kmgCustomer(): int
{
    kmgGuard();

    return (int) User::factory()->create()->id;
}

function kmgStaff(): int
{
    kmgGuard();

    return (int) SystemUser::factory()->create()->id;
}

function kmgRequirement(string $key, string $type, string $label): int
{
    return kmgRow('kyc_requirements', [
        'key' => $key, 'type' => $type, 'label' => $label, 'description' => null, 'is_enabled' => 0,
        'user_types' => '[]', 'purposes' => '[]', 'position' => 0, 'updated_by' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function kmgChange(int $requirement, int $staff): int
{
    return kmgRow('kyc_requirement_changes', [
        'kyc_requirement_id' => $requirement, 'old_state' => '{}', 'new_state' => '{}',
        'reason' => 'A synthetic change for the migration test', 'changed_by' => $staff, 'created_at' => now(),
    ]);
}

function kmgProfile(int $customer): int
{
    return kmgRow('kyc_profiles', [
        'user_id' => $customer, 'status' => 'not_started', 'status_changed_at' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function kmgSubmission(int $customer): int
{
    return kmgRow('kyc_submissions', [
        'reference' => 'KYC-'.strtoupper((string) Str::ulid()), 'user_id' => $customer, 'kind' => 'initial',
        'user_type' => 'subscriber', 'form_key' => (string) Str::uuid(), 'submitted_at' => now(), 'created_at' => now(),
    ]);
}

function kmgSubmissionRequirement(int $submission, int $requirement, string $key, string $type, string $label): void
{
    kmgRow('kyc_submission_requirements', [
        'kyc_submission_id' => $submission, 'kyc_requirement_id' => $requirement, 'requirement_key' => $key,
        'requirement_type' => $type, 'label' => $label, 'phone_fingerprint' => null, 'created_at' => now(),
    ]);
}

function kmgIdentity(int $submission, string $key): void
{
    kmgRow('kyc_submission_identities', [
        'kyc_submission_id' => $submission, 'requirement_key' => $key, 'ciphertext' => 'synthetic-ciphertext',
        'masked_value' => str_repeat("\u{2022}", 7).'0000', 'lookup_hash' => str_repeat('0', 64), 'created_at' => now(),
    ]);
}

/** The open slot. It has no id column, so it is written with insert() rather than insertGetId(). */
function kmgSlot(int $customer, int $submission): void
{
    kmgGuard();
    DB::table('kyc_open_submissions')->insert(['user_id' => $customer, 'kyc_submission_id' => $submission, 'created_at' => now()]);
}

function kmgReview(int $submission, int $staff, string $decision, ?string $reason): int
{
    return kmgRow('kyc_reviews', [
        'kyc_submission_id' => $submission, 'decision' => $decision, 'reason' => $reason, 'reviewed_by' => $staff,
        'decided_at' => now(), 'form_key' => (string) Str::uuid(), 'created_at' => now(),
    ]);
}

function kmgAudit(int $customer, int $submission): int
{
    return kmgRow('kyc_audit_events', [
        'event' => 'submission.opened', 'user_id' => $customer, 'kyc_submission_id' => $submission,
        'kyc_document_id' => null, 'system_user_id' => null,
        'metadata' => json_encode(['kind' => 'initial', 'requirement_count' => 0], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);
}

function kmgDocument(int $submission): int
{
    return kmgRow('kyc_documents', [
        'kyc_submission_id' => $submission, 'requirement_key' => 'document',
        'storage_name' => strtoupper((string) Str::ulid()).'.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1024,
        'sha256' => str_repeat('a', 64), 'uploaded_at' => now(), 'created_at' => now(),
    ]);
}

/** A customer's submission that asks for a BVN, with the BVN's snapshot and, when asked, its identity value. Returns the submission id. */
function kmgBvnSubmission(bool $identity = false): int
{
    $submission = kmgSubmission(kmgCustomer());
    kmgSubmissionRequirement($submission, kmgRequirement('bvn', 'bvn', 'BVN'), 'bvn', 'bvn', 'BVN');
    if ($identity) {
        kmgIdentity($submission, 'bvn');
    }

    return $submission;
}

/**
 * Writes the fewest rows that make $table the first KYC table with rows in the trigger migration's check order (KMG_TABLES).
 * A refused rollback must then name $table.
 */
function kmgSeed(string $table): void
{
    kmgGuard();

    switch ($table) {
        case 'kyc_requirements':
            kmgRequirement('bvn', 'bvn', 'BVN');
            break;
        case 'kyc_requirement_changes':
            kmgChange(kmgRequirement('bvn', 'bvn', 'BVN'), kmgStaff());
            break;
        case 'kyc_profiles':
            kmgProfile(kmgCustomer());
            break;
        case 'kyc_submissions':
            kmgSubmission(kmgCustomer());
            break;
        case 'kyc_submission_requirements':
            kmgBvnSubmission();
            break;
        case 'kyc_submission_identities':
            kmgBvnSubmission(identity: true);
            break;
        case 'kyc_open_submissions':
            $customer = kmgCustomer();
            kmgSlot($customer, kmgSubmission($customer));
            break;
        case 'kyc_reviews':
            $customer = kmgCustomer();
            kmgReview(kmgSubmission($customer), kmgStaff(), 'rejected', 'Please send a clearer copy.');
            break;
        case 'kyc_audit_events':
            $customer = kmgCustomer();
            kmgAudit($customer, kmgSubmission($customer));
            break;
        case 'kyc_documents':
            $submission = kmgSubmission(kmgCustomer());
            kmgSubmissionRequirement($submission, kmgRequirement('document', 'document', 'Provider document'), 'document', 'document', 'Provider document');
            kmgDocument($submission);
            break;
        default:
            throw new InvalidArgumentException("No seed is defined for {$table}.");
    }
}

/** The environment value with this name, or null when it is unset or blank. Callers never print the value. */
function kmgGuardSetting(string $name): ?string
{
    $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

    return is_string($value) && trim($value) !== '' ? $value : null;
}

/**
 * Points the restricted connection at the default connection's database, host and socket, with the named account. The URL is cleared,
 * so DB_URL cannot replace the account or the endpoint. A failed sign-in is reported without the driver's message, because that message
 * can name the account. The account must not be the default account, and the connection must pass kmgGuard().
 */
function kmgRestrictedConnect(#[SensitiveParameter] string $user, #[SensitiveParameter] string $password): void
{
    kmgGuard();
    if (in_array(strtolower($user), KMG_RESTRICTED_DENIED_NAMES, true)) {
        throw new RuntimeException('kmgRestrictedConnect refuses this account name: the restricted account must not be the default app account, the owner or root. Nothing was changed.');
    }
    $owner = (string) DB::selectOne('SELECT CURRENT_USER() AS account')->account;

    config(['database.connections.'.KMG_RESTRICTED_CONNECTION => array_merge(config('database.connections.mysql'), [
        'url' => '', 'username' => $user, 'password' => $password,
    ])]);
    DB::purge(KMG_RESTRICTED_CONNECTION);

    try {
        $signedIn = (string) DB::connection(KMG_RESTRICTED_CONNECTION)->selectOne('SELECT CURRENT_USER() AS account')->account;
    } catch (Throwable) {
        throw new RuntimeException('The restricted account could not sign in. Check NADABO_KYC_GUARD_DB_USERNAME and '
            .'NADABO_KYC_GUARD_DB_PASSWORD. The driver message is not shown, because it can name the account.');
    }

    if (! str_starts_with($signedIn, $user.'@') || $signedIn === $owner) {
        throw new RuntimeException('The restricted connection did not sign in as the account named by NADABO_KYC_GUARD_DB_USERNAME, '
            .'or it signed in as the default account. Nothing was changed.');
    }

    kmgGuard(KMG_RESTRICTED_CONNECTION);
}

/** Drops the restricted connection and its configuration. It does not throw, so a finally block can run it. */
function kmgRestrictedRelease(): void
{
    try {
        DB::purge(KMG_RESTRICTED_CONNECTION);
    } catch (Throwable) {
        // The configuration is removed below either way, so no later test can use the restricted account.
    }

    config(['database.connections.'.KMG_RESTRICTED_CONNECTION => null]);
}

/**
 * The restricted account's own grants, read with SHOW GRANTS. Only USAGE on every database and SELECT on the test database may be
 * granted, and nothing may carry WITH GRANT OPTION. A line of any other shape fails. Messages name privileges and objects, never accounts.
 */
function kmgRestrictedGrants(): void
{
    $unexpected = [];
    foreach (DB::connection(KMG_RESTRICTED_CONNECTION)->select('SHOW GRANTS') as $row) {
        $line = (string) array_values((array) $row)[0];
        if (! preg_match('/^GRANT (.+?) ON (.+?) TO \S/', $line, $match)) {
            $unexpected[] = 'a grant of an unexpected shape';

            continue;
        }

        $object = str_replace('\\_', '_', $match[2]);
        $grantOption = str_contains($line, 'WITH GRANT OPTION') ? ' WITH GRANT OPTION' : '';
        $privileges = array_map('trim', explode(',', (string) preg_replace('/\([^)]*\)/', '', $match[1])));
        foreach ($privileges as $privilege) {
            $allowed = ($privilege === 'USAGE' && $object === '*.*')
                || ($privilege === 'SELECT' && str_starts_with($object, '`nadabo_concurrency_test`.'));
            if (! $allowed || $grantOption !== '') {
                $unexpected[] = "{$privilege} on {$object}{$grantOption}";
            }
        }
    }

    expect($unexpected)->toBe([]);
}

/** Runs the restricted account through the reads the trigger migration's guard makes. Every one must succeed. */
function kmgRestrictedReads(): void
{
    $failed = [];
    foreach (KMG_RESTRICTED_READS as $sql) {
        try {
            DB::connection(KMG_RESTRICTED_CONNECTION)->select($sql);
        } catch (Throwable) {
            $failed[] = $sql;
        }
    }

    expect($failed)->toBe([]);
}

/**
 * Runs the restricted account through the delete the trigger migration's guard makes. The only outcome that passes is MariaDB's
 * privilege denial (error 1142). The statement matches no row, so it changes nothing if the account is allowed to run it. kmgGuard()
 * runs first, on the restricted connection, because the statement is still a DELETE.
 */
function kmgRestrictedDeleteDenied(): void
{
    kmgGuard(KMG_RESTRICTED_CONNECTION);

    try {
        DB::connection(KMG_RESTRICTED_CONNECTION)->delete(KMG_RESTRICTED_DELETE);
        $outcome = 'allowed';
    } catch (QueryException $e) {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $outcome = $code === 1142 ? 'denied' : "failed with driver code {$code}";
    }

    expect($outcome)->toBe('denied');
}

/** Runs the trigger migration alone under the restricted account. Its guard must refuse before any change. */
function kmgRestrictedMigrate(): int
{
    kmgGuard(KMG_RESTRICTED_CONNECTION);

    return Artisan::call('migrate', [
        '--database' => KMG_RESTRICTED_CONNECTION, '--path' => kmgPaths([KMG_TRIGGER_MIGRATION]), '--realpath' => true, '--force' => true,
    ]);
}

beforeEach(function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('The KYC migration suite needs MariaDB: run it with --configuration phpunit.kyc-migrations-mariadb.xml.');
    }

    // Read-only. The full guard runs before every test. Each test resets the database in its own body, after this check.
    kmgGuard();
});

it('runs on nadabo_concurrency_test on MariaDB, with the ten KYC tables and their twenty triggers after migrate:fresh', function () {
    expect((string) DB::selectOne('SELECT VERSION() AS version')->version)->toContain('MariaDB');
    kmgFresh();
    expect(kmgPresentTables())->toBe(KMG_TABLES);
    expect(kmgTriggers())->toBe(kmgSorted(KMG_TRIGGERS));
});

it('refuses to roll back while a KYC table holds a row, and changes nothing', function (string $table) {
    kmgFresh();
    kmgSeed($table);
    $counts = kmgCounts();
    $state = kmgState();

    expect(fn () => kmgRollBackAll())
        ->toThrow(RuntimeException::class, "Refusing to roll back: {$table} holds KYC configuration or records, which would be lost. Nothing was changed.");
    expect(kmgCounts())->toEqual($counts);
    expect(kmgState())->toEqual($state);
})->with(KMG_TABLES);

it('rolls back an empty KYC schema, removing its tables, triggers, migration rows and permissions, and nothing else changes', function () {
    kmgFresh();
    kmgWithPermissions();
    $before = kmgState();

    expect(kmgRollBackAll())->toBe(0);
    expect(kmgPresentTables())->toBe([]);
    expect(kmgTriggers())->toBe([]);
    expect(kmgKycMigrations())->toBe([]);
    expect(kmgPermissionNames())->toBe([]);
    expect(kmgGrants())->toBe(array_fill_keys(array_keys(KMG_ROLE_GRANTS), []));
    expect(Role::query()->where('guard_name', 'admin')->whereIn('name', array_keys(KMG_ROLE_GRANTS))->count())->toBe(4);

    $after = kmgState();
    expect($after['tables'])->toEqual($before['tables']);
    expect($after['migrations'])->toEqual($before['migrations']);
});

it('migrates again to the same schema, the same triggers and the same permission grants', function () {
    kmgFresh();
    kmgWithPermissions();
    $before = kmgState();

    expect(kmgRollBackAll())->toBe(0);
    expect(kmgMigrateAll())->toBe(0);

    expect(kmgState())->toEqual($before);
    expect(kmgTriggers())->toBe(kmgSorted(KMG_TRIGGERS));
});

it('keeps the slot-release trigger and the approval gate working after a re-migration', function () {
    kmgFresh();
    expect(kmgRollBackAll())->toBe(0);
    expect(kmgMigrateAll())->toBe(0);

    $customer = kmgCustomer();
    $staff = kmgStaff();
    $requirement = kmgRequirement('bvn', 'bvn', 'BVN');
    $submission = kmgSubmission($customer);
    kmgSubmissionRequirement($submission, $requirement, 'bvn', 'bvn', 'BVN');
    kmgSlot($customer, $submission);
    expect(DB::table('kyc_open_submissions')->where('kyc_submission_id', $submission)->count())->toBe(1);

    kmgReview($submission, $staff, 'rejected', 'Please send a clearer copy.');
    expect(DB::table('kyc_open_submissions')->count())->toBe(0);

    // The BVN requirement has no identity value, so the gate refuses this approval and no review is written.
    $second = kmgSubmission(kmgCustomer());
    kmgSubmissionRequirement($second, $requirement, 'bvn', 'bvn', 'BVN');
    expect(fn () => kmgReview($second, $staff, 'approved', null))
        ->toThrow(QueryException::class, 'A KYC approval needs a submission that asked only for a BVN or a NIN, with each identity value written.');
    expect(DB::table('kyc_reviews')->where('kyc_submission_id', $second)->count())->toBe(0);
});

it('creates every KYC trigger under the account that runs the migration', function () {
    kmgFresh();
    expect(kmgRollBackAll())->toBe(0);
    expect(kmgMigrateAll())->toBe(0);

    $account = (string) DB::selectOne('SELECT CURRENT_USER() AS account')->account;
    $definers = DB::table('information_schema.TRIGGERS')
        ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
        ->where('TRIGGER_NAME', 'like', 'kyc%')
        ->distinct()
        ->pluck('DEFINER')
        ->all();

    expect($definers)->toBe([$account]);
});

it('refuses the trigger migration under the restricted account, before the migration changes anything', function () {
    $user = kmgGuardSetting(KMG_GUARD_USERNAME_ENV);
    $password = kmgGuardSetting(KMG_GUARD_PASSWORD_ENV);
    if ($user === null || $password === null) {
        $this->markTestSkipped(KMG_GUARD_SKIP_REASON);
    }

    $changed = false;
    $failure = null;
    $restoreFailure = null;

    try {
        // Read-only: refuses before anything is reset.
        kmgGuard();

        // The baseline reset is the first change. A reset that fails part way may leave tables and triggers behind, so it is restored too.
        try {
            kmgFresh();
        } catch (Throwable $resetFailure) {
            $changed = true;

            throw $resetFailure;
        }

        kmgRestrictedConnect($user, $password);

        // Checks that change nothing. The SELECT probes must pass before the DELETE denial can be read as the reason for a refusal.
        kmgRestrictedGrants();
        kmgRestrictedReads();
        kmgRestrictedDeleteDenied();

        expect(kmgTriggers())->toBe(kmgSorted(KMG_TRIGGERS));
        expect(kmgNewestMigrations(1))->toBe([KMG_TRIGGER_MIGRATION]);

        // Set before the rollback: a rollback that fails part way may already have dropped some triggers.
        $changed = true;
        expect(kmgRollBackTriggers())->toBe(0);
        expect(kmgTriggers())->toBe([]);

        expect(fn () => kmgRestrictedMigrate())
            ->toThrow(RuntimeException::class, 'needs SELECT on the columns the KYC triggers read and DELETE on kyc_open_submissions');

        expect(kmgTriggers())->toBe([]);
        expect(DB::table('migrations')->where('migration', KMG_TRIGGER_MIGRATION)->exists())->toBeFalse();
        expect(kmgPresentTables())->toBe(KMG_TABLES);
        expect(kmgKycMigrations())->toBe(kmgSorted(array_values(array_diff(KMG_MIGRATIONS, [KMG_TRIGGER_MIGRATION]))));
    } catch (Throwable $caught) {
        $failure = $caught;
    } finally {
        kmgRestrictedRelease();
        if ($changed) {
            try {
                kmgFresh();
                expect(kmgKycMigrations())->toBe(kmgSorted(KMG_MIGRATIONS));
                expect(kmgTriggers())->toBe(kmgSorted(KMG_TRIGGERS));
            } catch (Throwable $restoreError) {
                $restoreFailure = $restoreError;
            }
        }
    }

    if ($restoreFailure !== null) {
        // A failed restore is never swallowed. Only assertion and RuntimeException messages are shown, because they are written here
        // and name no account. Any other exception shows its class only, because its message can name a database account.
        $cause = in_array(get_class($restoreFailure), [ExpectationFailedException::class, RuntimeException::class], true)
            ? $restoreFailure->getMessage()
            : get_class($restoreFailure).' (its message is not shown, because it can name a database account)';

        throw new RuntimeException("The test database was not restored to the KYC baseline after this test. Cause: {$cause}\n"
            .'It may be partly migrated: run migrate:fresh on nadabo_concurrency_test, then run the suite again.'
            .($failure !== null ? "\nThis test had already failed: ".get_class($failure).'.' : ''));
    }

    if ($failure !== null) {
        throw $failure;
    }
});
