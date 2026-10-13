<?php

use App\Actions\Customers\ChangeCustomerStatus;
use App\Actions\Customers\ChangeUserType;
use App\Actions\Customers\SendCustomerPasswordReset;
use App\Actions\Customers\UpdateCustomerProfile;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function staffAs(SystemRole|string $role = SystemRole::SuperAdmin): SystemUser
{
    $staff = SystemUser::factory()->create();
    $staff->assignRole($role instanceof SystemRole ? $role->value : $role);

    return $staff;
}

function customer(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

describe('list, search and filter', function () {
    it('lists customers with type and status, newest first, never staff', function () {
        $this->actingAs(staffAs(SystemRole::Viewer), 'admin');
        customer(['name' => 'Older Customer']);
        customer(['name' => 'Disabled Customer', 'status' => UserStatus::Disabled]);
        customer(['name' => 'Newest Vendor', 'user_type' => UserType::Vendor]);
        SystemUser::factory()->create(['name' => 'Staff Person']);

        $this->get('/admin/users')->assertOk()
            ->assertSeeInOrder(['Newest Vendor', 'Disabled Customer', 'Older Customer'])
            ->assertSee('Vendor')->assertSee('Disabled')
            ->assertDontSee('Staff Person')
            ->assertSee('data-nav="users"', false)->assertDontSee('is not built yet');
    });

    it('searches by name, email, phone (any format) and id', function () {
        $this->actingAs(staffAs(), 'admin');
        $ada = customer(['name' => 'Ada Obi', 'email' => 'ada@example.com', 'phone' => '08031112222']);
        customer(['name' => 'Bola Ade', 'email' => 'bola@example.com', 'phone' => '09045556666']);

        $this->get('/admin/users?q=ada')->assertSee('Ada Obi')->assertDontSee('Bola Ade');
        $this->get('/admin/users?q=bola@')->assertSee('Bola Ade')->assertDontSee('Ada Obi');
        $this->get('/admin/users?q=0803111')->assertSee('Ada Obi')->assertDontSee('Bola Ade');
        $this->get('/admin/users?q='.urlencode('+234 803 111 2222'))->assertSee('Ada Obi')->assertDontSee('Bola Ade');
        $this->get('/admin/users?q='.urlencode('+234 803 111'))->assertSee('Ada Obi')->assertDontSee('Bola Ade');
        $this->get('/admin/users?q='.urlencode('0904-555'))->assertSee('Bola Ade')->assertDontSee('Ada Obi');
        $this->get('/admin/users?q='.$ada->id)->assertSee('Ada Obi');
        $this->get('/admin/users?q=nobody-here')->assertSee('No customers found');
    });

    it('filters by type and status', function () {
        $this->actingAs(staffAs(), 'admin');
        customer(['name' => 'Sub Active']);
        customer(['name' => 'Vendor Active', 'user_type' => UserType::Vendor]);
        customer(['name' => 'Vendor Disabled', 'user_type' => UserType::Vendor, 'status' => UserStatus::Disabled]);
        customer(['name' => 'Api Active', 'user_type' => UserType::ApiUser]);

        $this->get('/admin/users?type=vendor')->assertSee('Vendor Active')->assertSee('Vendor Disabled')->assertDontSee('Sub Active')->assertDontSee('Api Active');
        $this->get('/admin/users?type=vendor&status=disabled')->assertSee('Vendor Disabled')->assertDontSee('Vendor Active');
        $this->get('/admin/users?type=api_user')->assertSee('Api Active')->assertDontSee('Sub Active');
        $this->get('/admin/users?type=owner')->assertSessionHasErrors('type');
        $this->get('/admin/users?status=paused')->assertSessionHasErrors('status');
    });

    it('paginates 20 per page and keeps filters in page links', function () {
        $this->actingAs(staffAs(), 'admin');
        User::factory()->count(25)->create(['user_type' => UserType::Affiliate]);

        $this->get('/admin/users?type=affiliate')->assertSee('Showing 1–20 of 25')->assertSee('type=affiliate&amp;page=2', false);
        $this->get('/admin/users?type=affiliate&page=2')->assertSee('Showing 21–25 of 25');
    });
});

describe('customer details', function () {
    it('shows safe customer details', function () {
        $c = customer(['name' => 'Ada Obi', 'email' => 'ada@example.com', 'phone' => '08031112222', 'user_type' => UserType::Affiliate]);
        $c->forceFill(['last_login_at' => now(), 'last_login_ip' => '203.0.113.77'])->save();
        $c->createToken('phone');

        $this->actingAs(staffAs(SystemRole::Viewer), 'admin')->get("/admin/users/{$c->id}")->assertOk()
            ->assertSee('Ada Obi')->assertSee('ada@example.com')->assertSee('08031112222')->assertSee('Affiliate')
            ->assertSeeInOrder(['data-field="tokens"', '>1<'], false)
            ->assertDontSee('203.0.113.77');
    });

    it('never renders passwords, hashes, remember tokens or API tokens', function () {
        $c = customer(['password' => 'CustomerSecret123']);
        $plainToken = $c->createToken('phone')->plainTextToken;
        $c->refresh();

        $this->actingAs(staffAs(), 'admin');
        foreach (['/admin/users', "/admin/users/{$c->id}", "/admin/users/{$c->id}/edit"] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            expect($html)->not->toContain('CustomerSecret123')
                ->not->toContain($c->password)
                ->not->toContain('$2y$')
                ->not->toContain($c->remember_token)
                ->not->toContain($plainToken)
                ->not->toContain(explode('|', $plainToken)[1]);
        }
    });

    it('shows each action only with its permission', function () {
        $c = customer();

        $this->actingAs(staffAs(SystemRole::Viewer), 'admin')->get("/admin/users/{$c->id}")
            ->assertDontSee('Edit details')->assertDontSee('Disable account')->assertDontSee('Change type')->assertDontSee('Send password reset link');

        $this->actingAs(staffAs(SystemRole::Support), 'admin')->get("/admin/users/{$c->id}")
            ->assertSee('Disable account')->assertDontSee('Edit details')->assertDontSee('Change type')->assertDontSee('Send password reset link');

        $this->actingAs(staffAs(SystemRole::Manager), 'admin')->get("/admin/users/{$c->id}")
            ->assertSee('Edit details')->assertSee('Disable account')->assertSee('Change type')->assertSee('Send password reset link');
    });

    it('returns 404 for unknown customers', function () {
        $this->actingAs(staffAs(), 'admin')->get('/admin/users/999999')->assertNotFound();
    });
});

describe('authorization', function () {
    it('keeps guests and customers out of every route', function () {
        $c = customer();
        $routes = [
            ['get', '/admin/users'], ['get', "/admin/users/{$c->id}"], ['get', "/admin/users/{$c->id}/edit"],
            ['put', "/admin/users/{$c->id}"], ['patch', "/admin/users/{$c->id}/status"],
            ['patch', "/admin/users/{$c->id}/type"], ['post', "/admin/users/{$c->id}/password-reset"],
        ];

        foreach ($routes as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('admin.login'));
        }

        $this->actingAs($c, 'web');
        foreach ($routes as [$method, $url]) {
            $this->{$method}($url, ['status' => 'disabled', 'user_type' => 'api_user', 'name' => 'x', 'email' => 'x@example.com', 'phone' => '08000000000'])
                ->assertRedirect(route('admin.login'));
        }

        expect($c->fresh()->isActive())->toBeTrue()->and($c->fresh()->user_type)->toBe(UserType::Subscriber);
    });

    it('enforces each permission on the server', function (SystemRole $role, array $expected) {
        $c = customer(['email' => 'target@example.com']);
        $this->actingAs(staffAs($role), 'admin');

        $responses = [
            'view' => $this->get("/admin/users/{$c->id}"),
            'edit' => $this->get("/admin/users/{$c->id}/edit"),
            'update' => $this->put("/admin/users/{$c->id}", ['name' => 'Changed', 'email' => 'target@example.com', 'phone' => $c->phone]),
            'status' => $this->patch("/admin/users/{$c->id}/status", ['status' => 'active']),
            'type' => $this->patch("/admin/users/{$c->id}/type", ['user_type' => 'subscriber']),
            'reset' => $this->post("/admin/users/{$c->id}/password-reset"),
        ];

        foreach ($responses as $action => $response) {
            in_array($action, $expected, true)
                ? expect($response->status())->not->toBe(403, "{$role->value} should be allowed to {$action}")
                : expect($response->status())->toBe(403, "{$role->value} should be refused {$action}");
        }
    })->with([
        'viewer' => [SystemRole::Viewer, ['view']],
        'finance' => [SystemRole::Finance, ['view']],
        'support' => [SystemRole::Support, ['view', 'status']],
        'manager' => [SystemRole::Manager, ['view', 'edit', 'update', 'status', 'type', 'reset']],
        'super admin' => [SystemRole::SuperAdmin, ['view', 'edit', 'update', 'status', 'type', 'reset']],
    ]);

    it('forbids the list to a role without customers.view', function () {
        $this->actingAs(staffAs(Role::create(['name' => 'Settings Only', 'guard_name' => 'admin'])->givePermissionTo('admin.access')->name), 'admin')
            ->get('/admin/users')->assertForbidden();
    });

    it('allows exactly what a custom role grants', function () {
        $role = Role::create(['name' => 'Reset Desk', 'guard_name' => 'admin'])->givePermissionTo(['admin.access', 'customers.view', 'customers.reset-password']);
        $c = customer();
        Notification::fake();
        $this->actingAs(staffAs($role->name), 'admin');

        $this->post("/admin/users/{$c->id}/password-reset")->assertSessionHasNoErrors();
        $this->patch("/admin/users/{$c->id}/status", ['status' => 'disabled'])->assertForbidden();
        Notification::assertSentTo($c, ResetPassword::class);
    });

    it('re-checks permissions inside every action', function () {
        $c = customer();
        $viewer = staffAs(SystemRole::Viewer);

        expect(fn () => app(ChangeCustomerStatus::class)->handle($c, UserStatus::Disabled, $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ChangeUserType::class)->handle($c, UserType::Vendor, $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(UpdateCustomerProfile::class)->handle($c, ['name' => 'x', 'email' => 'x@example.com', 'phone' => '08000000000'], $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SendCustomerPasswordReset::class)->handle($c, $viewer))->toThrow(AuthorizationException::class);
    });

    it('refuses everything to deactivated staff', function () {
        $super = staffAs();
        $this->actingAs($super, 'admin');
        $super->forceFill(['status' => 'disabled'])->save();

        $this->get('/admin/users')->assertRedirect(route('admin.login'));
        expect(fn () => app(ChangeCustomerStatus::class)->handle(customer(), UserStatus::Disabled, $super))->toThrow(AuthorizationException::class);
    });
});

describe('enable and disable', function () {
    it('disables a customer, revokes API tokens and ends remember-me logins', function () {
        $c = customer();
        $c->createToken('phone');
        $c->createToken('tablet');
        $oldRemember = $c->remember_token;

        $this->actingAs(staffAs(SystemRole::Support), 'admin')
            ->from("/admin/users/{$c->id}")
            ->patch("/admin/users/{$c->id}/status", ['status' => 'disabled'])
            ->assertRedirect("/admin/users/{$c->id}")
            ->assertSessionHas('status', 'Customer disabled. Their API tokens were revoked and they are signed out.');

        $c->refresh();
        expect($c->isActive())->toBeFalse()
            ->and(PersonalAccessToken::where('tokenable_id', $c->id)->count())->toBe(0)
            ->and($c->remember_token)->not->toBe($oldRemember);
    });

    it('re-enables a customer without restoring old tokens', function () {
        $c = customer(['status' => UserStatus::Disabled]);

        $this->actingAs(staffAs(), 'admin')->patch("/admin/users/{$c->id}/status", ['status' => 'active'])
            ->assertSessionHas('status', 'Customer enabled.');

        expect($c->fresh()->isActive())->toBeTrue()->and($c->tokens()->count())->toBe(0);
    });

    it('validates the status value', function () {
        $c = customer();

        $this->actingAs(staffAs(), 'admin')->patch("/admin/users/{$c->id}/status", ['status' => 'banned'])->assertSessionHasErrors('status');
        expect($c->fresh()->isActive())->toBeTrue();
    });

    it('ends a disabled customer\'s open web session on the next request', function () {
        $c = customer();
        $this->actingAs(staffAs(), 'admin')->patch("/admin/users/{$c->id}/status", ['status' => 'disabled']);

        $this->actingAs($c->fresh(), 'web')->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest('web');
    });

    it('refuses web login for a disabled customer', function () {
        $c = customer(['email' => 'blocked@example.com']);
        app(ChangeCustomerStatus::class)->handle($c, UserStatus::Disabled, staffAs());

        $this->post('/login', ['login' => 'blocked@example.com', 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest('web');
    });

    it('revokes API access for a disabled customer', function () {
        $c = customer(['email' => 'blocked@example.com']);
        $token = $c->createToken('phone')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/user')->assertOk();

        app(ChangeCustomerStatus::class)->handle($c, UserStatus::Disabled, staffAs());
        app('auth')->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/user')->assertUnauthorized();
        $this->postJson('/api/v1/auth/token', ['login' => 'blocked@example.com', 'password' => 'password', 'device_name' => 'x'])
            ->assertUnprocessable();
    });
});

describe('customer type', function () {
    it('changes type through ChangeUserType for every type', function (UserType $type) {
        $c = customer();

        $this->actingAs(staffAs(SystemRole::Manager), 'admin')
            ->patch("/admin/users/{$c->id}/type", ['user_type' => $type->value])
            ->assertSessionHas('status', "Customer type changed to {$type->label()}.");

        expect($c->fresh()->user_type)->toBe($type);
    })->with(UserType::cases());

    it('refuses type changes without customers.change-type', function (SystemRole $role) {
        $c = customer();

        $this->actingAs(staffAs($role), 'admin')->patch("/admin/users/{$c->id}/type", ['user_type' => 'api_user'])->assertForbidden();
        expect($c->fresh()->user_type)->toBe(UserType::Subscriber);
    })->with([SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

    it('rejects unknown types', function () {
        $c = customer();

        $this->actingAs(staffAs(), 'admin')->patch("/admin/users/{$c->id}/type", ['user_type' => 'reseller'])->assertSessionHasErrors('user_type');
        expect($c->fresh()->user_type)->toBe(UserType::Subscriber);
    });
});

describe('editing details', function () {
    it('edits name, email and phone, normalising and unverifying a changed email', function () {
        $c = customer(['email' => 'old@example.com']);

        $this->actingAs(staffAs(SystemRole::Manager), 'admin')->get("/admin/users/{$c->id}/edit")->assertOk()->assertSee('value="old@example.com"', false);
        $this->put("/admin/users/{$c->id}", ['name' => 'New Name', 'email' => 'New@Example.com', 'phone' => '+234 901 234 5678'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.show', $c))
            ->assertSessionHas('status', 'Customer details updated.');

        $c->refresh();
        expect($c->name)->toBe('New Name')->and($c->email)->toBe('new@example.com')
            ->and($c->phone)->toBe('09012345678')->and($c->email_verified_at)->toBeNull();
    });

    it('keeps verification when the email is unchanged', function () {
        $c = customer();

        $this->actingAs(staffAs(), 'admin')->put("/admin/users/{$c->id}", ['name' => 'Renamed', 'email' => $c->email, 'phone' => $c->phone])->assertSessionHasNoErrors();
        expect($c->fresh()->email_verified_at)->not->toBeNull();
    });

    it('ignores user_type, status and password sent with the edit form', function () {
        $c = customer();
        $hash = $c->password;

        $this->actingAs(staffAs(), 'admin')->put("/admin/users/{$c->id}", [
            'name' => 'Same', 'email' => $c->email, 'phone' => $c->phone,
            'user_type' => 'api_user', 'status' => 'disabled', 'password' => 'Injected123', 'email_verified_at' => null,
        ])->assertSessionHasNoErrors();

        $c->refresh();
        expect($c->user_type)->toBe(UserType::Subscriber)->and($c->isActive())->toBeTrue()->and($c->password)->toBe($hash)
            ->and($c->email_verified_at)->not->toBeNull();
    });

    it('validates details', function (array $overrides, array $errors) {
        customer(['email' => 'taken@example.com', 'phone' => '08039998888']);
        $c = customer();

        $this->actingAs(staffAs(), 'admin')
            ->put("/admin/users/{$c->id}", array_merge(['name' => 'Valid', 'email' => $c->email, 'phone' => $c->phone], $overrides))
            ->assertSessionHasErrors($errors);
    })->with([
        'missing name' => [['name' => ''], ['name']],
        'bad email' => [['email' => 'nope'], ['email']],
        'duplicate email' => [['email' => 'TAKEN@example.com'], ['email']],
        'missing phone' => [['phone' => ''], ['phone']],
        'bad phone' => [['phone' => '12345'], ['phone']],
        'duplicate phone' => [['phone' => '0803 999 8888'], ['phone']],
    ]);
});

describe('password reset', function () {
    it('emails a reset link without exposing or changing the password', function () {
        Notification::fake();
        $c = customer();
        $hash = $c->password;

        $this->actingAs(staffAs(SystemRole::Manager), 'admin')
            ->post("/admin/users/{$c->id}/password-reset")
            ->assertSessionHas('status', "Password reset link sent to {$c->email}.");

        Notification::assertSentTo($c, ResetPassword::class);
        expect($c->fresh()->password)->toBe($hash);
    });

    it('refuses resets for disabled customers', function () {
        Notification::fake();
        $c = customer(['status' => UserStatus::Disabled]);

        $this->actingAs(staffAs(), 'admin')->post("/admin/users/{$c->id}/password-reset")->assertSessionHasErrors('customer');
        Notification::assertNothingSent();
    });

    it('reports the broker throttle instead of sending twice', function () {
        Notification::fake();
        $c = customer();
        $this->actingAs(staffAs(), 'admin');

        $this->post("/admin/users/{$c->id}/password-reset")->assertSessionHasNoErrors();
        $this->post("/admin/users/{$c->id}/password-reset")->assertSessionHasErrors('customer');
        Notification::assertSentToTimes($c, ResetPassword::class, 1);
    });
});

describe('no deletion', function () {
    it('has no delete route for customers', function () {
        $c = customer();

        $this->actingAs(staffAs(), 'admin')->delete("/admin/users/{$c->id}")->assertMethodNotAllowed();
        expect(User::find($c->id))->not->toBeNull();
    });

    it('defines no customers.delete permission', function () {
        expect(SystemPermission::tryFrom('customers.delete'))->toBeNull()
            ->and(Permission::where('name', 'customers.delete')->exists())->toBeFalse();
    });
});

describe('permission rollout', function () {
    it('grants the new customer permissions to an existing Manager role exactly once', function () {
        $manager = Role::findByName('manager', 'admin');
        $manager->revokePermissionTo(['customers.update', 'customers.reset-password']);
        $migration = require database_path('migrations/2026_10_03_100000_grant_customer_management_permissions_to_manager.php');

        $migration->up();

        $manager->refresh();
        expect($manager->hasPermissionTo('customers.update'))->toBeTrue()
            ->and($manager->hasPermissionTo('customers.reset-password'))->toBeTrue()
            ->and(Role::findByName('support', 'admin')->hasPermissionTo('customers.update'))->toBeFalse();

        $migration->down();
        expect($manager->fresh()->hasPermissionTo('customers.update'))->toBeFalse();
    });
});
