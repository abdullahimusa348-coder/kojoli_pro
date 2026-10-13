<?php

use App\Actions\Admin\Kyc\SaveKycRequirement;
use App\Models\KycRequirement;
use App\Models\KycRequirementChange;
use App\Models\User;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP1 admin page: the requirements list and its change history (kyc.view), and the edit form and its save
 * (kyc.requirements). Authorization on every route and again in the action, validation, the stale-form refusal, one history
 * row per real change, escaping of staff-entered text, and that turning a requirement on blocks nothing for customers.
 */

beforeEach(fn () => kycSeed());

dataset('kyc page access', [
    'super admin' => [SystemRole::SuperAdmin, 200, 200],
    'manager' => [SystemRole::Manager, 200, 200],
    'support' => [SystemRole::Support, 200, 403],
    'finance' => [SystemRole::Finance, 403, 403],
    'viewer' => [SystemRole::Viewer, 403, 403],
]);

it('opens the page for staff with kyc.view, and the edit form only for staff with kyc.requirements', function (SystemRole $role, int $index, int $edit) {
    $this->actingAs(kycStaff($role), 'admin');

    $this->get(route('admin.kyc.requirements'))->assertStatus($index);
    $this->get(route('admin.kyc.requirements.edit', 'phone'))->assertStatus($edit);
})->with('kyc page access');

it('refuses a change from staff without kyc.requirements, and changes nothing', function (SystemRole $role) {
    $this->actingAs(kycStaff($role), 'admin');

    $this->put(route('admin.kyc.requirements.update', 'phone'), kycForm(kycRequirement('phone')))->assertForbidden();

    expect(KycRequirementChange::count())->toBe(0)
        ->and(kycRequirement('phone')->is_enabled)->toBeFalse();
})->with([SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

it('checks kyc.requirements again in the action, so a direct call from staff without it changes nothing', function (SystemRole $role) {
    $requirement = kycRequirement('phone');

    expect(fn () => app(SaveKycRequirement::class)->handle($requirement, 'Changed by a direct call', null, true, ['subscriber'],
        'A direct call from staff without the permission', kycStaff($role), SaveKycRequirement::fingerprint($requirement)))
        ->toThrow(AuthorizationException::class);

    expect(KycRequirementChange::count())->toBe(0)->and(kycRequirement('phone')->label)->toBe('Phone number');
})->with([SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

it('refuses the action for disabled staff, even with every KYC permission', function () {
    $staff = kycStaff(SystemRole::Manager);
    $staff->forceFill(['status' => 'disabled'])->save();
    $requirement = kycRequirement('phone');

    expect(fn () => app(SaveKycRequirement::class)->handle($requirement, 'Changed by disabled staff', null, true, ['subscriber'],
        'A save by a disabled administrator', $staff, SaveKycRequirement::fingerprint($requirement)))
        ->toThrow(AuthorizationException::class);
    expect(KycRequirementChange::count())->toBe(0);
});

it('needs kyc.view to open the page even with kyc.requirements, and kyc.requirements to change anything', function () {
    $this->actingAs(kycRoleStaff(['admin.access', 'kyc.requirements']), 'admin');
    $this->get(route('admin.kyc.requirements'))->assertForbidden();
    $this->get(route('admin.kyc.requirements.edit', 'phone'))->assertForbidden();

    $this->actingAs(kycRoleStaff(['admin.access', 'kyc.view']), 'admin');
    $this->get(route('admin.kyc.requirements'))->assertOk();
    $this->get(route('admin.kyc.requirements.edit', 'phone'))->assertForbidden();
});

it('sends guests and customer sessions to the staff login, and never changes anything for them', function () {
    $this->get(route('admin.kyc.requirements'))->assertRedirect(route('admin.login'));
    $this->actingAs(User::factory()->create(), 'web')->get(route('admin.kyc.requirements'))->assertRedirect(route('admin.login'));
    $this->actingAs(User::factory()->create(), 'web')
        ->put(route('admin.kyc.requirements.update', 'phone'), kycForm(kycRequirement('phone')))->assertRedirect(route('admin.login'));

    expect(KycRequirementChange::count())->toBe(0)->and(kycRequirement('phone')->is_enabled)->toBeFalse();
});

it('signs out disabled staff even when they hold kyc.requirements', function () {
    $staff = kycStaff(SystemRole::Manager);
    $staff->forceFill(['status' => 'disabled'])->save();

    $this->actingAs($staff, 'admin')->get(route('admin.kyc.requirements'))->assertRedirect(route('admin.login'));
});

it('shows the KYC item in the sidebar only to staff who may open it', function () {
    $this->actingAs(kycStaff(SystemRole::Support), 'admin')->get('/admin')->assertOk()->assertSee('data-nav="kyc"', false);
    $this->actingAs(kycStaff(SystemRole::Finance), 'admin')->get('/admin')->assertOk()->assertDontSee('data-nav="kyc"', false);
});

it('lists the four requirements, all off, with the note that nothing enforces them', function () {
    $this->actingAs(kycStaff(SystemRole::Support), 'admin')->get(route('admin.kyc.requirements'))
        ->assertOk()
        ->assertSeeInOrder(['data-kyc-requirement="phone"', 'data-kyc-requirement="bvn"', 'data-kyc-requirement="nin"', 'data-kyc-requirement="document"'], false)
        ->assertSee('data-status="off"', false)
        ->assertDontSee('data-status="on"', false)
        ->assertSee('Off · no customer types')
        ->assertSee('Nothing enforces KYC in this version.')
        ->assertSee('Not changed since it was set up')
        ->assertDontSee('data-edit-requirement', false);
});

it('offers edit links only to staff who may change requirements', function () {
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin')->get(route('admin.kyc.requirements'))
        ->assertOk()->assertSee('data-edit-requirement="phone"', false);
});

it('shows the saved settings on the edit form, with the fingerprint of the form', function () {
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $html = $this->get(route('admin.kyc.requirements.edit', 'phone'))->assertOk()
        ->assertSee('data-kyc-form="phone"', false)
        ->assertSee('name="fingerprint" value="'.SaveKycRequirement::fingerprint(kycRequirement('phone')).'"', false)
        ->getContent();

    expect(substr_count($html, 'name="user_types[]"'))->toBe(4);
});

it('saves an enabled requirement, records one history row with the settings before and after, and says so', function () {
    $staff = kycStaff(SystemRole::Manager);
    $this->actingAs($staff, 'admin');
    $before = kycRequirement('phone');

    $this->put(route('admin.kyc.requirements.update', 'phone'), kycForm($before, [
        'label' => 'Phone number on the account',
        'description' => 'Matches the number used at signup.',
        'user_types' => ['api_user', 'subscriber'],
        'reason' => 'Agreed with compliance for the verification step',
    ]))->assertRedirect(route('admin.kyc.requirements'))
        ->assertSessionHas('status', 'Phone number on the account saved.');

    $saved = kycRequirement('phone');
    $change = KycRequirementChange::sole();
    expect([$saved->label, $saved->description, $saved->is_enabled, $saved->user_types, $saved->updated_by])
        ->toBe(['Phone number on the account', 'Matches the number used at signup.', true, ['subscriber', 'api_user'], $staff->id])
        ->and($change->kyc_requirement_id)->toBe($saved->id)
        ->and($change->old_state)->toBe($before->snapshot())
        ->and($change->new_state)->toBe($saved->snapshot())
        ->and($change->reason)->toBe('Agreed with compliance for the verification step')
        ->and($change->changed_by)->toBe($staff->id);

    $this->get(route('admin.kyc.requirements'))->assertOk()
        ->assertSee('data-kyc-change="phone"', false)
        ->assertSee('Agreed with compliance for the verification step')
        ->assertSee('Subscriber, API User');
});

it('shows the save confirmation on the list after the redirect, in an accessible status message, and only once', function () {
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $html = $this->followingRedirects()
        ->put(route('admin.kyc.requirements.update', 'phone'), kycForm(kycRequirement('phone'), ['label' => 'Phone number on the account']))
        ->assertOk()
        ->getContent();

    expect($html)->toMatch('#role="status">\s*Phone number on the account saved\.\s*</div>#');

    $this->get(route('admin.kyc.requirements'))->assertOk()->assertDontSeeText('Phone number on the account saved.');
});

it('writes nothing and says so when the settings already say what the form says', function () {
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->put(route('admin.kyc.requirements.update', 'phone'), kycForm(kycRequirement('phone'), [
        'enabled' => '0', 'user_types' => [], 'reason' => 'Nothing actually changed here',
    ]))->assertRedirect(route('admin.kyc.requirements'))
        ->assertSessionHas('status', 'No changes to save for Phone number.');

    expect(KycRequirementChange::count())->toBe(0);
});

it('refuses an invalid form, showing the error, and changes nothing', function (array $overrides, string $field) {
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');
    $form = kycForm(kycRequirement('phone'), $overrides);
    $payload = Arr::except($form, array_keys(array_filter($overrides, fn ($value) => $value === null)));

    $this->put(route('admin.kyc.requirements.update', 'phone'), $payload)->assertSessionHasErrors($field);

    expect(KycRequirementChange::count())->toBe(0)->and(kycRequirement('phone')->label)->toBe('Phone number');
})->with([
    'a missing name' => [['label' => ''], 'label'],
    'a one-character name' => [['label' => 'A'], 'label'],
    'a name of 121 characters' => [['label' => str_repeat('P', 121)], 'label'],
    'a description of 501 characters' => [['description' => str_repeat('d', 501)], 'description'],
    'a customer type that does not exist' => [['user_types' => ['subscriber', 'admin']], 'user_types.1'],
    'a customer type chosen twice' => [['user_types' => ['vendor', 'vendor']], 'user_types.1'],
    'on with no customer type' => [['enabled' => '1', 'user_types' => []], 'user_types'],
    'a reason that is too short' => [['reason' => 'too short'], 'reason'],
    'no reason' => [['reason' => null], 'reason'],
    'no confirmation' => [['confirm' => null], 'confirm'],
    'no fingerprint' => [['fingerprint' => null], 'fingerprint'],
]);

it('shows no save confirmation when the form is refused, and shows the reason instead', function () {
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');
    $edit = route('admin.kyc.requirements.edit', 'phone');

    $this->from($edit)->put(route('admin.kyc.requirements.update', 'phone'), kycForm(kycRequirement('phone'), ['reason' => 'too short']))
        ->assertRedirect($edit)
        ->assertSessionHasErrors('reason')
        ->assertSessionMissing('status');

    $this->get($edit)->assertOk()
        ->assertSeeText('Give a reason of at least 10 characters')
        ->assertDontSeeText('Phone number saved.');

    expect(KycRequirementChange::count())->toBe(0);
});

it('refuses a save from a form opened before someone else saved, and writes nothing', function () {
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');
    $stale = kycForm(kycRequirement('phone'), ['label' => 'From the old form']);

    app(SaveKycRequirement::class)->handle(kycRequirement('phone'), 'Phone on file', null, false, [],
        'Another administrator changed the name', kycStaff(), SaveKycRequirement::fingerprint(kycRequirement('phone')));

    $this->put(route('admin.kyc.requirements.update', 'phone'), $stale)
        ->assertSessionHasErrors(['requirement' => SaveKycRequirement::STALE]);

    expect(kycRequirement('phone')->label)->toBe('Phone on file')
        ->and(KycRequirementChange::count())->toBe(1);
});

it('ignores any other field a form sends: the key, the type, the purposes and the position do not change', function () {
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->put(route('admin.kyc.requirements.update', 'phone'), kycForm(kycRequirement('phone'), [
        'key' => 'telephone', 'type' => 'nin', 'purposes' => ['wallet_funding'], 'position' => 1,
    ]))->assertRedirect();

    $saved = kycRequirement('phone');
    expect([$saved->key, $saved->type, $saved->purposes, $saved->position])->toBe(['phone', 'phone', [], 10]);
});

it('answers 404 for a requirement that does not exist, and for a key in the wrong form', function () {
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->get('/admin/kyc/requirements/nope/edit')->assertNotFound();
    $this->get('/admin/kyc/requirements/Bad_Key/edit')->assertNotFound();
    $this->put('/admin/kyc/requirements/nope', kycForm(kycRequirement('phone')))->assertNotFound();
});

it('shows staff-entered text escaped, in the list and in the history', function () {
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->put(route('admin.kyc.requirements.update', 'phone'), kycForm(kycRequirement('phone'), [
        'label' => 'Phone <script>alert(1)</script>',
        'reason' => 'Shown in history: <b>bold</b> & quoted "text"',
    ]))->assertRedirect();

    $this->get(route('admin.kyc.requirements'))->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('Phone &lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertSee('Shown in history: &lt;b&gt;bold&lt;/b&gt; &amp; quoted &quot;text&quot;', false);
});

it('escapes the saved name in the save confirmation', function () {
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->followingRedirects()
        ->put(route('admin.kyc.requirements.update', 'phone'), kycForm(kycRequirement('phone'), ['label' => 'Phone <b>number</b> check']))
        ->assertOk()
        ->assertSee('Phone &lt;b&gt;number&lt;/b&gt; check saved.', false)
        ->assertDontSee('<b>number</b>', false);
});

it('blocks nothing for customers: with every requirement on, they keep using the dashboard and the wallet', function () {
    foreach (KycRequirement::all() as $requirement) {
        $requirement->forceFill(['is_enabled' => true, 'user_types' => ['subscriber']])->save();
    }
    $customer = User::factory()->ofType(UserType::Subscriber)->create();

    $this->actingAs($customer, 'web');
    $this->get(route('dashboard'))->assertOk();
    $this->get(route('wallet'))->assertOk();
});
