<?php

use App\Actions\Admin\Kyc\SaveKycRequirement;
use App\Models\KycRequirementChange;
use App\Support\Enums\SystemRole;
use App\Support\Kyc\KycRequirementDefinitions;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP1 history: one append-only row per real change, holding the configuration before and after, the reason and
 * the staff member, chained to the change before it. A save that changes nothing writes nothing. A row can never be
 * edited or removed, and a row that does not match its requirement or its change is refused.
 */

beforeEach(fn () => kycSeed());

/** The seeded starting text of a requirement, as the history records it. */
function kycStartingDescription(string $key): string
{
    return collect(KycRequirementDefinitions::all())->firstWhere('key', $key)['description'];
}

it('writes one history row per real change, with the configuration before and after, the reason and the staff member', function () {
    $staff = kycStaff(SystemRole::Manager);
    $requirement = kycRequirement('nin');

    expect(app(SaveKycRequirement::class)->handle($requirement, 'NIN', null, true, ['vendor'],
        'First change to the NIN requirement', $staff, SaveKycRequirement::fingerprint($requirement)))->toBeTrue();

    $change = KycRequirementChange::sole();
    expect([$change->kyc_requirement_id, $change->changed_by, $change->reason])
        ->toBe([kycRequirement('nin')->id, $staff->id, 'First change to the NIN requirement'])
        ->and($change->old_state)->toBe(['label' => 'NIN', 'description' => kycStartingDescription('nin'),
            'is_enabled' => false, 'user_types' => [], 'purposes' => []])
        ->and($change->new_state)->toBe(['label' => 'NIN', 'description' => null,
            'is_enabled' => true, 'user_types' => ['vendor'], 'purposes' => []])
        ->and($change->new_state)->toBe(kycRequirement('nin')->snapshot());
});

it('chains each change to the one before it, and writes nothing for a save that changes nothing', function () {
    $staff = kycStaff();
    $save = app(SaveKycRequirement::class);

    $first = kycRequirement('bvn');
    $save->handle($first, 'BVN', null, true, ['subscriber'], 'Turned on for subscribers', $staff, SaveKycRequirement::fingerprint($first));

    $again = kycRequirement('bvn');
    expect($save->handle($again, 'BVN', null, true, ['subscriber'], 'Saved again with nothing changed', $staff,
        SaveKycRequirement::fingerprint($again)))->toBeFalse()
        ->and(KycRequirementChange::count())->toBe(1);

    $third = kycRequirement('bvn');
    $save->handle($third, 'BVN', null, true, ['subscriber', 'vendor'], 'Also for vendors now', $staff, SaveKycRequirement::fingerprint($third));

    [$earlier, $later] = KycRequirementChange::orderBy('id')->get()->all();
    expect($later->old_state)->toBe($earlier->new_state)
        ->and($later->new_state)->toBe(kycRequirement('bvn')->snapshot());
});

it('keeps the history of a staff member who has since been removed', function () {
    $staff = kycStaff();
    app(SaveKycRequirement::class)->handle(kycRequirement('phone'), 'Phone number', null, true, ['subscriber'],
        'Turned on for subscribers', $staff, SaveKycRequirement::fingerprint(kycRequirement('phone')));

    $staff->delete();

    expect(KycRequirementChange::sole()->changedBy?->name)->toBe($staff->name);
});

it('never updates or deletes a history row', function () {
    $staff = kycStaff();
    app(SaveKycRequirement::class)->handle(kycRequirement('phone'), 'Phone number', null, true, ['subscriber'],
        'Turned on for subscribers', $staff, SaveKycRequirement::fingerprint(kycRequirement('phone')));
    $change = KycRequirementChange::sole();

    expect(fn () => $change->forceFill(['reason' => 'Rewritten after the fact'])->save())->toThrow(LogicException::class, 'KYC requirement history is append-only.')
        ->and(fn () => $change->delete())->toThrow(LogicException::class, 'KYC requirement history is append-only.')
        ->and(KycRequirementChange::sole()->reason)->toBe('Turned on for subscribers');
});

it('refuses a history row that does not match its requirement or the change it records', function (Closure $alter, string $message) {
    $staff = kycStaff();
    $requirement = kycRequirement('phone');
    $requirement->forceFill(['is_enabled' => true, 'user_types' => ['subscriber']])->save();
    $current = kycRequirement('phone')->snapshot();
    $valid = [
        'kyc_requirement_id' => $requirement->id,
        'old_state' => ['label' => 'Phone number', 'description' => kycStartingDescription('phone'),
            'is_enabled' => false, 'user_types' => [], 'purposes' => []],
        'new_state' => $current,
        'reason' => 'Turned on for subscribers',
        'changed_by' => $staff->id,
    ];

    expect(fn () => (new KycRequirementChange)->forceFill($alter($valid))->save())->toThrow(LogicException::class, $message);
    expect(KycRequirementChange::count())->toBe(0);
})->with([
    'a reason of nine characters' => [fn (array $row) => array_replace($row, ['reason' => 'too short']),
        'A KYC requirement change needs a reason of 10 to 500 characters.'],
    'no staff member' => [fn (array $row) => array_replace($row, ['changed_by' => null]),
        'A KYC requirement change records the staff member who made it.'],
    'an old state with missing keys' => [fn (array $row) => array_replace($row, ['old_state' => ['label' => 'Phone number']]),
        'A KYC requirement change records the configuration before and after, in full.'],
    'an old state equal to the new one' => [fn (array $row) => array_replace($row, ['old_state' => $row['new_state']]),
        'A KYC requirement change records a real change.'],
    'a new state the requirement does not have' => [fn (array $row) => array_replace($row, ['new_state' => array_replace($row['new_state'], ['label' => 'Something else'])]),
        'A KYC requirement change records the configuration the requirement now has.'],
    'no requirement' => [fn (array $row) => array_replace($row, ['kyc_requirement_id' => 999_999]),
        'A KYC requirement change belongs to a KYC requirement.'],
]);
