<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 CP2a: database triggers that keep KYC history append-only, keep a decided submission closed and refuse an approval the
 * submission cannot back, as defence in depth behind the model guards. Each table below refuses every UPDATE and every DELETE, so a query
 * builder, a raw statement or a second application cannot rewrite or remove a submission, a decision, an identity value or an audit event.
 * - Requirements, identity values and documents refuse an INSERT once their submission has a decision (a review row exists), so an
 *   approved submission keeps the requirements it was judged on. A new open slot for a decided submission is refused the same way.
 *   On MariaDB and MySQL these checks lock the submission row and read the reviews with a locking read, so a write that races a
 *   decision waits for it and then sees it.
 * - Recording a review releases the submission's open slot in the same statement, so no committed review leaves the slot behind.
 * - Recording an approval is refused, whichever statement writes it, unless the submission has at least one requirement, every
 *   requirement is a BVN or a NIN, and each of those has an identity value. The database cannot check the keyed values (the phone
 *   fingerprint and an identity's lookup hash are keyed with the app key), so the application still checks those before it approves.
 *   On MariaDB and MySQL the approval locks the submission row and reads its requirements and identity values with locking reads, so a
 *   requirement or identity that another transaction is still writing is waited for, and one it has committed is seen.
 * - MariaDB and MySQL: triggers that SIGNAL SQLSTATE 45000. SQLite: triggers that RAISE(ABORT). SQLite serializes writers, so a plain
 *   check is enough.
 * A trigger runs with the privileges of the account that created it. On MariaDB and MySQL this migration first checks that its own
 * account can read the columns the triggers name and can delete open slots, and refuses before it changes anything if it cannot. The
 * privileges that account needs are listed in docs/ARCHITECTURE.md (Production database privileges).
 * Any other driver is refused rather than left unprotected. Limits, stated here because the tests cannot show them: TRUNCATE and DROP
 * TABLE do not fire row triggers, and the open-slot table has no UPDATE or DELETE trigger. A decision removes its slot through the
 * release trigger, which runs with the migration account's privileges, so the application's database user should not hold TRUNCATE,
 * DROP, ALTER or TRIGGER on KYC tables, and should hold no DELETE on any KYC table.
 * Rolling back is refused while any KYC table holds a row. Rollbacks run newest first, so this migration is the first to run and
 * refuses before any other migration changes anything. MariaDB applies DDL without a transaction; docs/ARCHITECTURE.md describes how to
 * recover a partly applied migration.
 */
return new class extends Migration
{
    /** Tables whose rows are written once and never changed or removed. */
    private const IMMUTABLE = [
        'kyc_requirement_changes', 'kyc_submissions', 'kyc_submission_requirements', 'kyc_submission_identities',
        'kyc_reviews', 'kyc_documents', 'kyc_audit_events',
    ];

    /** Tables whose rows belong to one submission and may not be added once that submission has a decision. */
    private const AFTER_DECISION = ['kyc_submission_requirements', 'kyc_submission_identities', 'kyc_documents'];

    /** Every KYC table, in the order a refusal names them: tables that reference others first. */
    private const TABLES = ['kyc_documents', 'kyc_audit_events', 'kyc_open_submissions', 'kyc_reviews',
        'kyc_submission_identities', 'kyc_submission_requirements', 'kyc_submissions', 'kyc_profiles',
        'kyc_requirement_changes', 'kyc_requirements'];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("KYC history triggers need MariaDB, MySQL or SQLite; the {$driver} driver is not supported.");
        }
        if ($driver !== 'sqlite') {
            $this->assertDefinerCanRun();
        }

        foreach (self::IMMUTABLE as $table) {
            foreach (['update', 'delete'] as $operation) {
                $name = $this->name($table, $operation);
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
                DB::unprepared($this->create($driver, $table, $operation, $name));
            }
        }

        foreach (self::AFTER_DECISION as $table) {
            $name = $this->afterDecisionName($table);
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            DB::unprepared($this->createAfterDecision($driver, $table, $name));
        }

        DB::unprepared('DROP TRIGGER IF EXISTS kyc_open_submissions_no_insert_after_decision');
        DB::unprepared($this->createSlotAfterDecision($driver));

        DB::unprepared('DROP TRIGGER IF EXISTS kyc_reviews_release_open_slot');
        DB::unprepared($this->createReviewReleasesSlot($driver));

        DB::unprepared('DROP TRIGGER IF EXISTS kyc_reviews_approval_gate');
        DB::unprepared($this->createApprovalGate($driver));
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to roll back: {$table} holds KYC configuration or records, which would be lost. Nothing was changed.");
            }
        }

        foreach (self::IMMUTABLE as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$this->name($table, $operation));
            }
        }

        foreach (self::AFTER_DECISION as $table) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->afterDecisionName($table));
        }
        DB::unprepared('DROP TRIGGER IF EXISTS kyc_open_submissions_no_insert_after_decision');
        DB::unprepared('DROP TRIGGER IF EXISTS kyc_reviews_release_open_slot');
        DB::unprepared('DROP TRIGGER IF EXISTS kyc_reviews_approval_gate');
    }

    /**
     * The triggers run with the privileges of the account that creates them, which is the account running this migration. That account
     * must read the columns the triggers name and must be able to delete open slots. The reads match no rows and the delete matches none
     * either (submission ids are positive), so this writes nothing. It runs before any trigger is dropped or created.
     */
    private function assertDefinerCanRun(): void
    {
        $reads = [
            'SELECT id FROM kyc_submissions WHERE 1 = 0',
            'SELECT kyc_submission_id, decision FROM kyc_reviews WHERE 1 = 0',
            'SELECT kyc_submission_id, requirement_key, requirement_type FROM kyc_submission_requirements WHERE 1 = 0',
            'SELECT kyc_submission_id, requirement_key FROM kyc_submission_identities WHERE 1 = 0',
            'SELECT kyc_submission_id FROM kyc_documents WHERE 1 = 0',
            'SELECT kyc_submission_id FROM kyc_open_submissions WHERE 1 = 0',
        ];

        try {
            foreach ($reads as $sql) {
                DB::select($sql);
            }
            DB::delete('DELETE FROM kyc_open_submissions WHERE kyc_submission_id = -1');
        } catch (QueryException) {
            throw new RuntimeException('The account running this migration needs SELECT on the columns the KYC triggers read and DELETE on '
                .'kyc_open_submissions, because the triggers run with its privileges. Nothing was changed. Grant them, then run the migration '
                .'again (docs/ARCHITECTURE.md, Production database privileges).');
        }
    }

    private function name(string $table, string $operation): string
    {
        return "{$table}_no_{$operation}";
    }

    private function afterDecisionName(string $table): string
    {
        return "{$table}_no_insert_after_decision";
    }

    private function create(string $driver, string $table, string $operation, string $name): string
    {
        $when = strtoupper($operation);
        $message = "KYC history is append-only: a row in {$table} cannot be changed or removed.";

        if ($driver === 'sqlite') {
            return "CREATE TRIGGER {$name} BEFORE {$when} ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END";
        }

        return "CREATE TRIGGER {$name} BEFORE {$when} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'";
    }

    private function createAfterDecision(string $driver, string $table, string $name): string
    {
        return $this->createDecidedGuard($driver, $table, $name, $this->afterDecisionMessage($table));
    }

    private function createSlotAfterDecision(string $driver): string
    {
        return $this->createDecidedGuard($driver, 'kyc_open_submissions', 'kyc_open_submissions_no_insert_after_decision',
            'A decided KYC submission cannot hold the open slot.');
    }

    /**
     * Refuses the insert when a review already exists for the row's submission. MariaDB and MySQL lock the submission row first and
     * read the reviews with a locking read, so the check sees a decision that is committed or still in progress.
     */
    private function createDecidedGuard(string $driver, string $table, string $name, string $message): string
    {
        if ($driver === 'sqlite') {
            return "CREATE TRIGGER {$name} BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}') "
                .'WHERE EXISTS (SELECT 1 FROM kyc_reviews WHERE kyc_submission_id = NEW.kyc_submission_id); END';
        }

        return "CREATE TRIGGER {$name} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN "
            .'DECLARE parent_rows INT DEFAULT 0; DECLARE review_rows INT DEFAULT 0; '
            .'SELECT COUNT(*) INTO parent_rows FROM kyc_submissions WHERE id = NEW.kyc_submission_id FOR UPDATE; '
            .'SELECT COUNT(*) INTO review_rows FROM kyc_reviews WHERE kyc_submission_id = NEW.kyc_submission_id LOCK IN SHARE MODE; '
            ."IF review_rows > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
    }

    private function createReviewReleasesSlot(string $driver): string
    {
        if ($driver === 'sqlite') {
            return 'CREATE TRIGGER kyc_reviews_release_open_slot AFTER INSERT ON kyc_reviews BEGIN '
                .'DELETE FROM kyc_open_submissions WHERE kyc_submission_id = NEW.kyc_submission_id; END';
        }

        return 'CREATE TRIGGER kyc_reviews_release_open_slot AFTER INSERT ON kyc_reviews FOR EACH ROW '
            .'DELETE FROM kyc_open_submissions WHERE kyc_submission_id = NEW.kyc_submission_id';
    }

    /**
     * Refuses an approval the submission cannot back: no requirement at all, a requirement that is neither a BVN nor a NIN, or a BVN or
     * NIN requirement with no identity value under the same key. A rejection and a request for more information are never refused here.
     * The rules are structural, so the database can check them for any writer. Values that need the app key are checked by the application.
     * MariaDB and MySQL read with locking reads, not a snapshot: a plain read in a transaction that began earlier could miss a requirement
     * another session has just committed, so the approval would pass on a stale view. The submission row is locked first, and the
     * requirement and identity reads follow it, so a concurrent requirement, identity or document insert waits for the approval.
     */
    private function createApprovalGate(string $driver): string
    {
        $message = 'A KYC approval needs a submission that asked only for a BVN or a NIN, with each identity value written.';
        $refused = "NEW.decision = 'approved' AND ("
            .'NOT EXISTS (SELECT 1 FROM kyc_submission_requirements WHERE kyc_submission_id = NEW.kyc_submission_id) OR '
            ."EXISTS (SELECT 1 FROM kyc_submission_requirements WHERE kyc_submission_id = NEW.kyc_submission_id AND requirement_type NOT IN ('bvn', 'nin')) OR "
            .'EXISTS (SELECT 1 FROM kyc_submission_requirements AS r WHERE r.kyc_submission_id = NEW.kyc_submission_id'
            ." AND r.requirement_type IN ('bvn', 'nin')"
            .' AND NOT EXISTS (SELECT 1 FROM kyc_submission_identities AS i'
            .' WHERE i.kyc_submission_id = r.kyc_submission_id AND i.requirement_key = r.requirement_key)))';

        if ($driver === 'sqlite') {
            return "CREATE TRIGGER kyc_reviews_approval_gate BEFORE INSERT ON kyc_reviews WHEN {$refused} "
                ."BEGIN SELECT RAISE(ABORT, '{$message}'); END";
        }

        return 'CREATE TRIGGER kyc_reviews_approval_gate BEFORE INSERT ON kyc_reviews FOR EACH ROW BEGIN '
            .'DECLARE locked_rows INT DEFAULT 0; DECLARE requirement_rows INT DEFAULT 0; DECLARE supported_rows INT DEFAULT 0; '
            .'DECLARE identity_rows INT DEFAULT 0; '
            ."IF NEW.decision = 'approved' THEN "
            .'SELECT COUNT(*) INTO locked_rows FROM kyc_submissions WHERE id = NEW.kyc_submission_id LOCK IN SHARE MODE; '
            .'SELECT COUNT(*) INTO requirement_rows FROM kyc_submission_requirements WHERE kyc_submission_id = NEW.kyc_submission_id LOCK IN SHARE MODE; '
            ."SELECT COUNT(*) INTO supported_rows FROM kyc_submission_requirements WHERE kyc_submission_id = NEW.kyc_submission_id AND requirement_type IN ('bvn', 'nin') LOCK IN SHARE MODE; "
            .'SELECT COUNT(*) INTO identity_rows FROM kyc_submission_requirements AS r INNER JOIN kyc_submission_identities AS i '
            .'ON i.kyc_submission_id = r.kyc_submission_id AND i.requirement_key = r.requirement_key '
            ."WHERE r.kyc_submission_id = NEW.kyc_submission_id AND r.requirement_type IN ('bvn', 'nin') LOCK IN SHARE MODE; "
            .'IF requirement_rows = 0 OR supported_rows < requirement_rows OR identity_rows < supported_rows THEN '
            ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; "
            .'END IF; END';
    }

    private function afterDecisionMessage(string $table): string
    {
        $noun = match ($table) {
            'kyc_submission_requirements' => 'requirement',
            'kyc_submission_identities' => 'identity value',
            default => 'document',
        };

        return "A KYC submission takes no new {$noun} once it has a decision.";
    }
};
