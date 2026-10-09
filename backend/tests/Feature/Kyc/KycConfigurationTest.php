<?php

use App\Models\KycProfile;
use App\Models\KycRequirement;
use App\Models\KycSubmission;
use App\Models\User;
use App\Support\Enums\UserType;
use App\Support\Kyc\KycStatus;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\KycRequirementsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP1, the configuration foundation on SQLite: the four tables and nothing later, the seeded requirements (all
 * off), customer-type applicability, the model rules, and the immutable submission and status rows. Nothing here reads
 * or writes customer KYC data, and nothing is enforced.
 */

it('creates the four configuration tables and none of the later KYC or virtual-account tables', function () {
    foreach (['kyc_requirements', 'kyc_requirement_changes', 'kyc_profiles', 'kyc_submissions'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} missing");
    }
    foreach (['kyc_submission_items', 'kyc_decisions', 'kyc_documents', 'kyc_document_accesses', 'virtual_accounts',
        'virtual_account_providers', 'virtual_account_provider_credentials', 'virtual_account_credits',
        'virtual_account_webhooks', 'virtual_account_status_changes'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} exists");
    }
});

it('keeps exactly the columns the configuration needs', function () {
    expect(Schema::getColumnListing('kyc_requirements'))->toEqual(['id', 'key', 'type', 'label', 'description', 'is_enabled',
        'user_types', 'purposes', 'position', 'updated_by', 'created_at', 'updated_at'])
        ->and(Schema::getColumnListing('kyc_requirement_changes'))->toEqual(['id', 'kyc_requirement_id', 'old_state', 'new_state', 'reason', 'changed_by', 'created_at'])
        ->and(Schema::getColumnListing('kyc_profiles'))->toEqual(['id', 'user_id', 'status', 'status_changed_at', 'created_at', 'updated_at'])
        ->and(Schema::getColumnListing('kyc_submissions'))->toEqual(['id', 'reference', 'user_id', 'submitted_at', 'created_at']);
});

it('seeds the four requirements, all off, with no customer types and no purposes', function () {
    kycSeed();

    $requirements = KycRequirement::orderBy('position')->get();

    expect($requirements->pluck('key')->all())->toBe(['phone', 'bvn', 'nin', 'document'])
        ->and($requirements->pluck('type')->all())->toBe(['phone', 'bvn', 'nin', 'document'])
        ->and($requirements->pluck('label')->all())->toBe(['Phone number', 'BVN', 'NIN', 'Provider document'])
        ->and($requirements->every(fn (KycRequirement $requirement) => $requirement->is_enabled === false))->toBeTrue()
        ->and($requirements->every(fn (KycRequirement $requirement) => $requirement->user_types === [] && $requirement->purposes === []))->toBeTrue()
        ->and($requirements->every(fn (KycRequirement $requirement) => $requirement->updated_by === null))->toBeTrue();
});

it('is part of the normal deployment seed, and seeding again changes nothing', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(KycRequirement::count())->toBe(4)
        ->and(KycRequirement::where('is_enabled', true)->exists())->toBeFalse();
});

it('never overwrites a requirement that staff have changed, and creates only the missing ones', function () {
    kycSeed();
    kycRequirement('phone')->forceFill(['label' => 'Phone on file', 'is_enabled' => true, 'user_types' => ['vendor']])->save();
    DB::table('kyc_requirements')->where('key', 'nin')->delete();

    (new KycRequirementsSeeder)->run();

    expect(KycRequirement::count())->toBe(4)
        ->and(kycRequirement('phone')->label)->toBe('Phone on file')
        ->and(kycRequirement('phone')->is_enabled)->toBeTrue()
        ->and(kycRequirement('phone')->user_types)->toBe(['vendor'])
        ->and(kycRequirement('nin')->is_enabled)->toBeFalse();
});

it('starts every requirement off and applies it to no customer type', function () {
    kycSeed();

    foreach (KycRequirement::all() as $requirement) {
        foreach (UserType::cases() as $type) {
            expect([$requirement->appliesTo($type), $requirement->isRequiredFor($type)])->toBe([false, false], "{$requirement->key} / {$type->value}");
        }
    }
});

it('applies a requirement only to the customer types it selects, and is required only while it is on', function (string $value) {
    kycSeed();
    $type = UserType::from($value);
    $selected = in_array($value, ['subscriber', 'api_user'], true);
    $requirement = kycRequirement('phone');
    $requirement->forceFill(['user_types' => ['subscriber', 'api_user']])->save();

    expect([$requirement->appliesTo($type), $requirement->isRequiredFor($type)])->toBe([$selected, false]);

    $requirement->forceFill(['is_enabled' => true])->save();

    expect($requirement->fresh()->isRequiredFor($type))->toBe($selected);
})->with(['subscriber', 'vendor', 'affiliate', 'api_user']);

it('lists the customer types in the usual order and refuses unknown, repeated or out-of-order ones', function (array $types) {
    kycSeed();

    expect(fn () => kycRequirement('phone')->forceFill(['user_types' => $types])->save())
        ->toThrow(LogicException::class, 'A KYC requirement applies to known customer types, each once, in the usual order.');
})->with([
    'an unknown type' => [['subscriber', 'admin']],
    'a repeated type' => [['vendor', 'vendor']],
    'out of order' => [['api_user', 'subscriber']],
]);

it('refuses to turn a requirement on for no customer type', function () {
    kycSeed();

    expect(fn () => kycRequirement('phone')->forceFill(['is_enabled' => true])->save())
        ->toThrow(LogicException::class, 'A KYC requirement can be on only for at least one customer type.');
    expect(kycRequirement('phone')->is_enabled)->toBeFalse();
});

it('keeps a key and a type once the requirement exists', function (string $attribute, string $value) {
    kycSeed();

    expect(fn () => kycRequirement('phone')->forceFill([$attribute => $value])->save())
        ->toThrow(LogicException::class, 'A KYC requirement keeps its key and type.');
})->with([
    'key' => ['key', 'telephone'],
    'type' => ['type', 'nin'],
]);

it('refuses a requirement with an unknown type, a bad key or a name or description out of bounds', function (array $attributes, string $message) {
    kycSeed();

    expect(fn () => KycRequirement::factory()->create($attributes))->toThrow(LogicException::class, $message);
})->with([
    'an unknown type' => [['type' => 'bank'], 'A KYC requirement is a phone, BVN, NIN or provider-document requirement.'],
    'a key with capital letters' => [['key' => 'Phone'], 'A KYC requirement key is 2 to 40 lower-case letters, digits or hyphens, starting with a letter.'],
    'a one-character name' => [['label' => 'A'], 'A KYC requirement has a name of 2 to 120 characters.'],
    'a name of 121 characters' => [['label' => str_repeat('a', 121)], 'A KYC requirement has a name of 2 to 120 characters.'],
    'a description of 501 characters' => [['description' => str_repeat('d', 501)], 'A KYC requirement has a description of up to 500 characters.'],
]);

it('stores purpose keys for later gating, and refuses a purpose that is not a key', function () {
    kycSeed();
    $requirement = kycRequirement('bvn');

    $requirement->forceFill(['purposes' => ['wallet_funding']])->save();

    expect(kycRequirement('bvn')->purposes)->toBe(['wallet_funding'])
        ->and(fn () => $requirement->forceFill(['purposes' => ['Wallet Funding']])->save())
        ->toThrow(LogicException::class, 'A KYC purpose is a unique lower-case key of 2 to 40 characters.');
});

it('never deletes a requirement', function () {
    kycSeed();

    expect(fn () => kycRequirement('phone')->delete())->toThrow(LogicException::class, 'KYC requirements are never deleted.')
        ->and(KycRequirement::count())->toBe(4);
});

it('addresses a requirement by its key in URLs, not by its id', function () {
    kycSeed();

    expect(kycRequirement('phone')->getRouteKey())->toBe('phone')
        ->and(route('admin.kyc.requirements.edit', kycRequirement('phone')))->toEndWith('/admin/kyc/requirements/phone/edit');
});

it('keeps a submission immutable and never deletes one', function () {
    $submission = KycSubmission::factory()->create();

    expect(fn () => $submission->forceFill(['submitted_at' => now()->addDay()])->save())->toThrow(LogicException::class, 'KYC submissions are immutable.')
        ->and(fn () => $submission->delete())->toThrow(LogicException::class, 'KYC submissions are never deleted.')
        ->and(KycSubmission::count())->toBe(1);
});

it('refuses a submission whose reference is not a KYC reference, and a reused reference', function () {
    $submission = KycSubmission::factory()->create();

    expect(fn () => KycSubmission::factory()->create(['reference' => 'PUR-'.strtoupper((string) Str::ulid())]))
        ->toThrow(LogicException::class, 'A KYC submission has a KYC- reference of 26 upper-case letters and digits.')
        ->and(fn () => KycSubmission::factory()->create(['reference' => $submission->reference]))->toThrow(QueryException::class);
});

it('keeps one KYC status per customer, typed, starting as not started', function () {
    $customer = User::factory()->create();
    DB::table('kyc_profiles')->insert(['user_id' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);

    expect(DB::table('kyc_profiles')->where('user_id', $customer->id)->value('status'))->toBe('not_started');

    $other = User::factory()->create();
    KycProfile::factory()->status(KycStatus::PendingReview)->create(['user_id' => $other->id]);

    expect(KycProfile::where('user_id', $other->id)->sole()->status)->toBe(KycStatus::PendingReview)
        ->and(fn () => KycProfile::factory()->create(['user_id' => $other->id]))->toThrow(QueryException::class);
});

it('refuses to delete a customer who has a KYC row', function () {
    $customer = User::factory()->create();
    KycSubmission::factory()->create(['user_id' => $customer->id]);

    expect(fn () => DB::table('users')->where('id', $customer->id)->delete())->toThrow(QueryException::class)
        ->and(User::whereKey($customer->id)->exists())->toBeTrue();
});
