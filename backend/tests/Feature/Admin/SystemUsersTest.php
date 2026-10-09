<?php

use App\Actions\Admin\SystemUsers\ChangeSystemUserStatus;
use App\Actions\Admin\SystemUsers\CreateSystemUser;
use App\Actions\Admin\SystemUsers\SystemUserRules;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function staff(SystemRole $role = SystemRole::SuperAdmin, array $attributes = []): SystemUser
{
    return SystemUser::factory()->withRole($role)->create($attributes);
}

function newStaffPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Amina Yusuf',
        'email' => 'Amina.Yusuf@Example.com',
        'phone' => '+234 803 555 0101',
        'role' => 'support',
        'status' => 'active',
        'password' => 'StaffPass123',
        'password_confirmation' => 'StaffPass123',
    ], $overrides);
}

/** Manager who has additionally been granted system-users.manage (not a default grant). */
function managerWithAccess(): SystemUser
{
    $manager = staff(SystemRole::Manager);
    $manager->givePermissionTo(SystemPermission::SystemUsersManage->value);

    return $manager;
}

describe('authorization', function () {
    it('lets super admin open every System Users page', function () {
        $super = staff();
        $other = staff(SystemRole::Viewer);

        $this->actingAs($super, 'admin')->get('/admin/system-users')->assertOk()->assertSee('Add system user');
        $this->get('/admin/system-users/create')->assertOk();
        $this->get("/admin/system-users/{$other->id}/edit")->assertOk();
    });

    it('forbids every System Users route to roles without system-users.manage', function (SystemRole $role) {
        $actor = staff($role);
        $target = staff(SystemRole::Viewer);

        $this->actingAs($actor, 'admin');
        $this->get('/admin/system-users')->assertForbidden();
        $this->get('/admin/system-users/create')->assertForbidden();
        $this->post('/admin/system-users', newStaffPayload())->assertForbidden();
        $this->get("/admin/system-users/{$target->id}/edit")->assertForbidden();
        $this->put("/admin/system-users/{$target->id}", ['name' => 'x', 'email' => 'x@example.com', 'role' => 'viewer'])->assertForbidden();
        $this->patch("/admin/system-users/{$target->id}/status", ['status' => 'disabled'])->assertForbidden();
        $this->delete("/admin/system-users/{$target->id}")->assertForbidden();

        expect($target->fresh()->isActive())->toBeTrue()
            ->and(SystemUser::where('email', 'amina.yusuf@example.com')->exists())->toBeFalse();
    })->with([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

    it('keeps customers and guests out', function () {
        $target = staff(SystemRole::Viewer);

        $this->get('/admin/system-users')->assertRedirect(route('admin.login'));
        $this->post('/admin/system-users', newStaffPayload())->assertRedirect(route('admin.login'));

        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/admin/system-users')->assertRedirect(route('admin.login'));
        $this->delete("/admin/system-users/{$target->id}")->assertRedirect(route('admin.login'));

        expect($target->fresh())->not->toBeNull();
    });

    it('signs out a deactivated staff member on their next request', function () {
        $super = staff();
        $this->actingAs($super, 'admin');
        $super->forceFill(['status' => 'disabled'])->save();

        $this->get('/admin/system-users')->assertRedirect(route('admin.login'));
    });

    it('stops non super admins from creating or changing Super Admins', function () {
        $manager = managerWithAccess();
        $super = staff();

        $this->actingAs($manager, 'admin');
        $this->get('/admin/system-users/create')->assertOk()->assertDontSee('value="super-admin"', false);
        $this->post('/admin/system-users', newStaffPayload(['role' => 'super-admin']))->assertForbidden();
        $this->put("/admin/system-users/{$super->id}", ['name' => 'x', 'email' => $super->email, 'role' => 'viewer'])->assertForbidden();
        $this->patch("/admin/system-users/{$super->id}/status", ['status' => 'disabled'])->assertForbidden();
        $this->delete("/admin/system-users/{$super->id}")->assertForbidden();

        expect($super->fresh()->isSuperAdmin())->toBeTrue()->and($super->fresh()->isActive())->toBeTrue();
    });

    it('lets staff granted system-users.manage manage non super admin accounts', function () {
        $this->actingAs(managerWithAccess(), 'admin')
            ->post('/admin/system-users', newStaffPayload())
            ->assertRedirect(route('admin.system-users'));

        expect(SystemUser::firstWhere('email', 'amina.yusuf@example.com')->primaryRole())->toBe(SystemRole::Support);
    });

    it('re-checks authorization inside the create action', function () {
        expect(fn () => app(CreateSystemUser::class)->handle(newStaffPayload(), staff(SystemRole::Viewer)))
            ->toThrow(AuthorizationException::class);
    });
});

describe('list, search and filter', function () {
    it('lists staff with role and status, never customers', function () {
        $this->actingAs(staff(attributes: ['name' => 'Musa Admin']), 'admin');
        staff(SystemRole::Finance, ['name' => 'Bola Finance']);
        staff(SystemRole::Viewer, ['name' => 'Chidi Viewer', 'status' => UserStatus::Disabled]);
        User::factory()->create(['name' => 'Customer Person']);

        $this->get('/admin/system-users')
            ->assertSeeInOrder(['Bola Finance', 'Chidi Viewer', 'Musa Admin'])
            ->assertSee('Finance')
            ->assertSee('Inactive')
            ->assertSee('(you)')
            ->assertDontSee('Customer Person');
    });

    it('searches by name, email and phone', function () {
        $this->actingAs(staff(attributes: ['name' => 'Musa Admin']), 'admin');
        staff(SystemRole::Viewer, ['name' => 'Bola Ade', 'email' => 'bola@example.com', 'phone' => '08031112222']);
        staff(SystemRole::Viewer, ['name' => 'Chidi Okafor', 'email' => 'chidi@example.com']);

        $this->get('/admin/system-users?q=bola')->assertSee('Bola Ade')->assertDontSee('Chidi Okafor');
        $this->get('/admin/system-users?q=chidi@')->assertSee('Chidi Okafor')->assertDontSee('Bola Ade');
        $this->get('/admin/system-users?q=0803111')->assertSee('Bola Ade')->assertDontSee('Chidi Okafor');
        $this->get('/admin/system-users?q=nobody')->assertSee('No system users found');
    });

    it('filters by role and status', function () {
        $actor = staff(attributes: ['name' => 'Musa Admin']);
        $this->actingAs($actor, 'admin');
        staff(SystemRole::Finance, ['name' => 'Bola Finance']);
        staff(SystemRole::Finance, ['name' => 'Dayo Finance', 'status' => UserStatus::Disabled]);
        staff(SystemRole::Support, ['name' => 'Chidi Support']);

        // The signed-in actor's name is always in the top bar, so check list rows by id.
        $this->get('/admin/system-users?role=finance')
            ->assertSee('Bola Finance')->assertSee('Dayo Finance')->assertDontSee('Chidi Support')
            ->assertDontSee('data-system-user="'.$actor->id.'"', false);
        $this->get('/admin/system-users?role=finance&status=disabled')
            ->assertSee('Dayo Finance')->assertDontSee('Bola Finance');
        $this->get('/admin/system-users?status=nonsense')->assertSessionHasErrors('status');
    });

    it('hides deleted staff from the list', function () {
        $this->actingAs(staff(), 'admin');
        staff(SystemRole::Viewer, ['name' => 'Gone Person'])->delete();

        $this->get('/admin/system-users')->assertDontSee('Gone Person');
    });
});

describe('create', function () {
    it('creates a staff account with a hashed password, normalised fields and one role', function () {
        $this->actingAs(staff(), 'admin')
            ->post('/admin/system-users', newStaffPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.system-users'))
            ->assertSessionHas('status', 'Staff account for Amina Yusuf created.');

        $created = SystemUser::firstWhere('email', 'amina.yusuf@example.com');
        expect($created->phone)->toBe('08035550101')
            ->and($created->isActive())->toBeTrue()
            ->and($created->getRoleNames()->all())->toBe(['support'])
            ->and($created->password)->not->toBe('StaffPass123')
            ->and(Hash::check('StaffPass123', $created->password))->toBeTrue()
            ->and(User::count())->toBe(0);
    });

    it('can create an inactive account that cannot sign in', function () {
        $this->actingAs(staff(), 'admin')->post('/admin/system-users', newStaffPayload(['status' => 'disabled']));

        auth('admin')->logout();
        $this->post('/admin/login', ['email' => 'amina.yusuf@example.com', 'password' => 'StaffPass123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    });

    it('validates input', function (array $overrides, array $errors) {
        staff(SystemRole::Viewer, ['email' => 'taken@example.com', 'phone' => '08039998888']);

        $this->actingAs(staff(), 'admin')
            ->post('/admin/system-users', newStaffPayload($overrides))
            ->assertSessionHasErrors($errors);

        expect(SystemUser::where('name', 'Amina Yusuf')->exists())->toBeFalse();
    })->with([
        'missing name' => [['name' => ''], ['name']],
        'bad email' => [['email' => 'not-an-email'], ['email']],
        'duplicate email' => [['email' => 'TAKEN@example.com'], ['email']],
        'bad phone' => [['phone' => '12345'], ['phone']],
        'duplicate phone' => [['phone' => '0803 999 8888'], ['phone']],
        'unknown role' => [['role' => 'owner'], ['role']],
        'missing role' => [['role' => ''], ['role']],
        'bad status' => [['status' => 'paused'], ['status']],
        'weak password' => [['password' => 'weak', 'password_confirmation' => 'weak'], ['password']],
        'unconfirmed password' => [['password_confirmation' => 'Different123'], ['password']],
        'missing password' => [['password' => '', 'password_confirmation' => ''], ['password']],
    ]);

    it('allows a staff email that also belongs to a customer (separate account systems)', function () {
        User::factory()->create(['email' => 'shared@example.com']);

        $this->actingAs(staff(), 'admin')
            ->post('/admin/system-users', newStaffPayload(['email' => 'shared@example.com']))
            ->assertSessionHasNoErrors();
    });
});

describe('update', function () {
    it('updates details and role, keeping the password when left blank', function () {
        $target = staff(SystemRole::Viewer, ['email' => 'old@example.com']);
        $oldHash = $target->password;

        $this->actingAs(staff(), 'admin')
            ->put("/admin/system-users/{$target->id}", [
                'name' => 'New Name', 'email' => 'New@Example.com', 'phone' => '09012345678', 'role' => 'finance',
                'password' => '', 'password_confirmation' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.system-users'));

        $target->refresh();
        expect($target->name)->toBe('New Name')
            ->and($target->email)->toBe('new@example.com')
            ->and($target->phone)->toBe('09012345678')
            ->and($target->getRoleNames()->all())->toBe(['finance'])
            ->and($target->password)->toBe($oldHash);
    });

    it('changes the password when a new one is given', function () {
        $target = staff(SystemRole::Viewer);

        $this->actingAs(staff(), 'admin')->put("/admin/system-users/{$target->id}", [
            'name' => $target->name, 'email' => $target->email, 'role' => 'viewer',
            'password' => 'NewStaffPass123', 'password_confirmation' => 'NewStaffPass123',
        ])->assertSessionHasNoErrors();

        expect(Hash::check('NewStaffPass123', $target->fresh()->password))->toBeTrue();
    });

    it('validates updates', function () {
        staff(SystemRole::Viewer, ['email' => 'taken@example.com']);
        $target = staff(SystemRole::Viewer);

        $this->actingAs(staff(), 'admin')->put("/admin/system-users/{$target->id}", [
            'name' => '', 'email' => 'taken@example.com', 'role' => 'owner', 'password' => 'weak', 'password_confirmation' => 'weak',
        ])->assertSessionHasErrors(['name', 'email', 'role', 'password']);
    });

    it('lets staff keep their own email when saving', function () {
        $target = staff(SystemRole::Viewer, ['email' => 'same@example.com', 'phone' => '08030000001']);

        $this->actingAs(staff(), 'admin')->put("/admin/system-users/{$target->id}", [
            'name' => 'Renamed', 'email' => 'same@example.com', 'phone' => '08030000001', 'role' => 'viewer',
        ])->assertSessionHasNoErrors();
    });

    it('does not let staff change their own role', function () {
        $super = staff();
        staff(); // another super admin, so the last-admin rule is not what blocks it

        $this->actingAs($super, 'admin')->put("/admin/system-users/{$super->id}", [
            'name' => 'Me', 'email' => $super->email, 'role' => 'viewer',
        ])->assertSessionHasErrors('role');

        expect($super->fresh()->isSuperAdmin())->toBeTrue();
    });

    it('lets staff edit their own details without changing role', function () {
        $super = staff();

        $this->actingAs($super, 'admin')->get("/admin/system-users/{$super->id}/edit")
            ->assertOk()->assertSee('You cannot change your own role.');

        $this->put("/admin/system-users/{$super->id}", ['name' => 'Renamed Me', 'email' => $super->email, 'role' => 'super-admin'])
            ->assertSessionHasNoErrors();

        expect($super->fresh()->name)->toBe('Renamed Me');
    });

    it('guards the last active Super Admin in the shared rules', function () {
        $rules = app(SystemUserRules::class);
        $only = staff();
        staff(attributes: ['status' => UserStatus::Disabled]); // an inactive super admin does not count

        expect(fn () => $rules->ensureNotLastActiveSuperAdmin($only, 'blocked'))
            ->toThrow(ValidationException::class);

        staff(); // a second active super admin
        $rules->ensureNotLastActiveSuperAdmin($only, 'blocked');

        expect(true)->toBeTrue();
    });

    it('lets a Super Admin demote another Super Admin while one stays active', function () {
        $actor = staff();
        $target = staff();

        $this->actingAs($actor, 'admin')->put("/admin/system-users/{$target->id}", ['name' => $target->name, 'email' => $target->email, 'role' => 'manager'])
            ->assertSessionHasNoErrors();

        expect($target->fresh()->primaryRole())->toBe(SystemRole::Manager);
    });

    it('returns 404 for deleted staff', function () {
        $gone = staff(SystemRole::Viewer);
        $gone->delete();

        $this->actingAs(staff(), 'admin')->get("/admin/system-users/{$gone->id}/edit")->assertNotFound();
    });
});

describe('activate, deactivate and delete', function () {
    it('deactivates and reactivates a staff account', function () {
        $target = staff(SystemRole::Support, ['name' => 'Target Person']);
        $this->actingAs(staff(), 'admin');

        $this->patch("/admin/system-users/{$target->id}/status", ['status' => 'disabled'])
            ->assertSessionHas('status', 'Target Person deactivated.');
        expect($target->fresh()->isActive())->toBeFalse();

        $this->patch("/admin/system-users/{$target->id}/status", ['status' => 'active'])
            ->assertSessionHas('status', 'Target Person activated.');
        expect($target->fresh()->isActive())->toBeTrue();

        $this->patch("/admin/system-users/{$target->id}/status", ['status' => 'paused'])->assertSessionHasErrors('status');
    });

    it('refuses a deactivated staff member at login', function () {
        $target = staff(SystemRole::Support, ['email' => 'target@example.com']);
        $this->actingAs(staff(), 'admin')->patch("/admin/system-users/{$target->id}/status", ['status' => 'disabled']);

        auth('admin')->logout();
        $this->post('/admin/login', ['email' => 'target@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    });

    it('does not let staff deactivate or delete themselves', function () {
        $super = staff();
        staff();

        $this->actingAs($super, 'admin');
        $this->patch("/admin/system-users/{$super->id}/status", ['status' => 'disabled'])
            ->assertSessionHasErrors(['system_user' => 'You cannot deactivate your own account.']);
        $this->delete("/admin/system-users/{$super->id}")
            ->assertSessionHasErrors(['system_user' => 'You cannot delete your own account.']);

        expect($super->fresh()->isActive())->toBeTrue()->and($super->fresh()->trashed())->toBeFalse();
    });

    it('never leaves the system without an active Super Admin', function () {
        $actor = staff();
        $onlyOtherSuper = staff();
        $this->actingAs($actor, 'admin');

        // Two active super admins: deactivating one is allowed.
        $this->patch("/admin/system-users/{$onlyOtherSuper->id}/status", ['status' => 'disabled'])->assertSessionHasNoErrors();

        // $actor is now the last active Super Admin; even the action itself refuses to deactivate them.
        expect(fn () => app(ChangeSystemUserStatus::class)->handle($actor, UserStatus::Disabled, $actor))
            ->toThrow(ValidationException::class);
        expect($actor->fresh()->isActive())->toBeTrue();
    });

    it('soft deletes staff, keeps the record and blocks login', function () {
        $target = staff(SystemRole::Finance, ['name' => 'Leaving Person', 'email' => 'leaving@example.com']);

        $this->actingAs(staff(), 'admin')->delete("/admin/system-users/{$target->id}")
            ->assertRedirect(route('admin.system-users'))
            ->assertSessionHas('status', 'Leaving Person deleted.');

        $trashed = SystemUser::withTrashed()->find($target->id);
        expect($trashed->trashed())->toBeTrue()
            ->and($trashed->isActive())->toBeFalse()
            ->and(SystemUser::find($target->id))->toBeNull();

        auth('admin')->logout();
        $this->post('/admin/login', ['email' => 'leaving@example.com', 'password' => 'password'])->assertSessionHasErrors('email');

        // The email stays reserved.
        $this->actingAs(staff(), 'admin')->post('/admin/system-users', newStaffPayload(['email' => 'leaving@example.com']))
            ->assertSessionHasErrors('email');
    });

    it('ends the admin session of a deleted staff member', function () {
        $target = staff(SystemRole::Support);
        $target->delete();

        $this->actingAs($target, 'admin')->get('/admin')->assertRedirect(route('admin.login'));
    });
});

describe('password secrecy', function () {
    it('never renders passwords or hashes on any System Users page', function () {
        $super = staff(attributes: ['password' => 'SuperSecret123']);
        $target = staff(SystemRole::Viewer, ['password' => 'ViewerSecret123']);

        $this->actingAs($super, 'admin');
        $pages = [
            $this->get('/admin/system-users')->getContent(),
            $this->get('/admin/system-users/create')->getContent(),
            $this->get("/admin/system-users/{$target->id}/edit")->getContent(),
        ];

        foreach ($pages as $html) {
            expect($html)->not->toContain($super->password)
                ->and($html)->not->toContain($target->password)
                ->and($html)->not->toContain('SuperSecret123')
                ->and($html)->not->toContain('ViewerSecret123')
                ->and($html)->not->toContain('$2y$')
                ->and($html)->not->toContain($target->remember_token);
        }
    });

    it('does not echo a submitted password back after a validation error', function () {
        $this->actingAs(staff(), 'admin')
            ->from('/admin/system-users/create')
            ->post('/admin/system-users', newStaffPayload(['name' => '', 'password' => 'TypedSecret123', 'password_confirmation' => 'TypedSecret123']));

        expect($this->get('/admin/system-users/create')->getContent())->not->toContain('TypedSecret123');
    });

    it('hides password fields when a staff record is serialized', function () {
        $array = staff()->toArray();

        expect($array)->not->toHaveKey('password')->not->toHaveKey('remember_token')->not->toHaveKey('last_login_ip');
    });
});
