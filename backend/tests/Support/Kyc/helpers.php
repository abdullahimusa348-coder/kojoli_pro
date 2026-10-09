<?php

use App\Actions\Admin\Kyc\SaveKycRequirement;
use App\Models\KycRequirement;
use App\Models\SystemUser;
use App\Support\Enums\SystemRole;
use Database\Seeders\KycRequirementsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
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
