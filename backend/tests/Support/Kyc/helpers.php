<?php

use App\Actions\Admin\Kyc\SaveKycRequirement;
use App\Models\KycRequirement;
use App\Models\KycReview;
use App\Models\KycSubmission;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Kyc\KycSubmissionLedger;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserType;
use App\Support\Kyc\KycStatus;
use App\Support\Kyc\KycSubmissionKind;
use Database\Seeders\KycRequirementsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/*
 * Shared helpers for the Phase 13 CP1 KYC configuration tests (feature and MariaDB concurrency). Nothing here holds
 * customer or identity data: requirements carry only their configuration and staff names.
 */

/** The eight permissions Phase 13 CP1 adds (SystemPermission). */
const KYC_PERMISSION_NAMES = ['kyc.view', 'kyc.review', 'kyc.requirements', 'kyc.documents',
    'virtual-accounts.view', 'virtual-accounts.manage', 'virtual-accounts.providers', 'virtual-accounts.credentials'];

const KYC_PERMISSIONS_MIGRATION = '2026_10_08_100000_create_kyc_permissions';

const KYC_TABLES_MIGRATION = '2026_10_08_100100_create_kyc_configuration_tables';

const KYC_TRIGGERS_MIGRATION = '2026_10_09_100400_create_kyc_history_immutability_triggers';

/** Every KYC migration, in the order they run: Phase 13 CP1, then CP2a. */
const KYC_MIGRATIONS = [KYC_PERMISSIONS_MIGRATION, KYC_TABLES_MIGRATION,
    '2026_10_09_100000_extend_kyc_submissions_for_snapshots',
    '2026_10_09_100100_create_kyc_submission_snapshot_tables',
    '2026_10_09_100200_create_kyc_review_and_open_submission_tables',
    '2026_10_09_100300_create_kyc_document_and_audit_tables',
    '2026_10_09_100400_create_kyc_history_immutability_triggers'];

/** Synthetic identity numbers for the CP2a tests. They belong to nobody. */
const KYC_TEST_BVN = '22222222222';

const KYC_TEST_NIN = '33333333333';

/** The roles, the permissions and the four requirements, as a fresh deployment has them. */
function kycSeed(): void
{
    (new RolesAndPermissionsSeeder)->run();
    (new KycRequirementsSeeder)->run();
}

/** A staff member with a built-in role (run kycSeed first). */
function kycStaff(SystemRole $role = SystemRole::SuperAdmin): SystemUser
{
    $staff = SystemUser::factory()->create();
    $staff->assignRole($role->value);

    return $staff;
}

/** A staff member whose custom role holds exactly $permissions. */
function kycRoleStaff(array $permissions): SystemUser
{
    $role = Role::create(['name' => 'KYC '.Str::random(8), 'guard_name' => 'admin'])->givePermissionTo($permissions);
    $staff = SystemUser::factory()->create();
    $staff->assignRole($role->name);

    return $staff;
}

function kycRequirement(string $key = 'phone'): KycRequirement
{
    return KycRequirement::where('key', $key)->sole();
}

/** A valid edit-form submission for $requirement as it is now, with overrides. It turns the requirement on for Subscribers. */
function kycForm(KycRequirement $requirement, array $values = []): array
{
    return $values + [
        'label' => $requirement->label,
        'description' => (string) $requirement->description,
        'enabled' => '1',
        'user_types' => ['subscriber'],
        'reason' => 'Agreed for the launch of the verification step',
        'confirm' => '1',
        'fingerprint' => SaveKycRequirement::fingerprint($requirement),
    ];
}

/** A customer of the given type, with an email and a phone number, as registration creates one. */
function kycCustomer(UserType $type = UserType::Subscriber): User
{
    return User::factory()->ofType($type)->create();
}

/** Turns a requirement on for the given customer types through the real save path, so its history is written (run kycSeed first). */
function kycEnable(string $key, array $types = ['subscriber']): KycRequirement
{
    $requirement = kycRequirement($key);
    app(SaveKycRequirement::class)->handle($requirement, $requirement->label, $requirement->description, true, $types,
        'Turned on for the KYC submission tests', kycStaff(), SaveKycRequirement::fingerprint($requirement));

    return kycRequirement($key);
}

/** Opens a submission through the ledger, with a fresh form key unless one is given. */
function kycOpen(User $customer, array $numbers = ['bvn' => KYC_TEST_BVN, 'nin' => KYC_TEST_NIN],
    KycSubmissionKind $kind = KycSubmissionKind::Initial, ?string $formKey = null): KycSubmission
{
    return app(KycSubmissionLedger::class)->open($customer, $kind, $numbers, $formKey ?? (string) Str::uuid());
}

/** Records a staff decision through the ledger, with a fresh form key unless one is given. */
function kycDecide(KycSubmission $submission, SystemUser $staff, KycStatus $decision, ?string $reason = null, ?string $formKey = null): KycReview
{
    return app(KycSubmissionLedger::class)->decide($submission, $staff, $decision, $reason, $formKey ?? (string) Str::uuid());
}

/** Drops the cached encrypter, so the next use builds one from the app key and previous keys in the configuration. */
function kycResetEncrypter(): void
{
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}
