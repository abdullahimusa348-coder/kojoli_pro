<?php

use App\Support\Enums\SystemRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP1 keeps KYC apart from the Phase 11 NIN and BVN purchase data. No KYC file, view, migration, seeder or factory
 * refers to that data, the configuration tables hold no identity or document field, and the requirement pages never query
 * the purchase identity or result tables.
 */

/** Class, table and route names that belong to the Phase 11 purchase identity code. */
const KYC_PHASE11_TOKENS = ['PurchaseIdentityRecipient', 'purchase_identity_recipients', 'IdentityHasher', 'RecipientType',
    'PurchaseResult', 'purchase_results', 'IdentityBuyController', 'IdentityStaff', 'identity-search'];

/** Every file that belongs to the KYC configuration foundation, as paths. */
function kycFoundationFiles(): array
{
    return collect([
        File::glob(app_path('Models/Kyc*.php')),
        File::allFiles(app_path('Support/Kyc')),
        File::allFiles(app_path('Actions/Admin/Kyc')),
        File::allFiles(app_path('Http/Requests/Admin/Kyc')),
        [app_path('Http/Controllers/Admin/KycRequirementController.php')],
        File::allFiles(resource_path('views/admin/kyc')),
        File::glob(database_path('migrations/*_kyc_*.php')),
        [database_path('seeders/KycRequirementsSeeder.php')],
        File::glob(database_path('factories/Kyc*.php')),
    ])->flatten()->map(fn ($file) => is_string($file) ? $file : $file->getPathname())->values()->all();
}

beforeEach(fn () => kycSeed());

it('finds the whole KYC foundation before checking it', function () {
    expect(count(kycFoundationFiles()))->toBeGreaterThanOrEqual(19);
});

it('keeps the KYC files free of references to the Phase 11 NIN and BVN purchase data', function () {
    $references = collect(kycFoundationFiles())
        ->mapWithKeys(fn (string $file) => [Str::after($file, base_path().'/') => collect(KYC_PHASE11_TOKENS)
            ->filter(fn (string $token) => str_contains(File::get($file), $token))->values()->all()])
        ->filter(fn (array $found) => $found !== []);

    expect($references->all())->toBe([]);
});

it('creates no configuration column that holds an identity number, a document or a secret', function () {
    foreach (['kyc_requirements', 'kyc_requirement_changes', 'kyc_profiles', 'kyc_submissions'] as $table) {
        expect(collect(Schema::getColumnListing($table))->intersect(['bvn', 'nin', 'number', 'identity_number', 'document',
            'document_path', 'secret', 'value', 'encrypted_value'])->values()->all())->toBe([], $table);
    }
});

it('never queries the purchase identity or result tables while the requirement pages render', function () {
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->get(route('admin.kyc.requirements'))->assertOk();
    $this->get(route('admin.kyc.requirements.edit', 'nin'))->assertOk();

    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'purchase_identity') || str_contains($sql, 'purchase_results'))->values()->all())->toBe([]);
});
