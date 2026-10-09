<?php

use App\Actions\Customers\ChangeUserType;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('lets super admins and managers change a customer type', function (SystemRole $role) {
    $customer = User::factory()->create();
    $staff = SystemUser::factory()->withRole($role)->create();

    app(ChangeUserType::class)->handle($customer, UserType::Vendor, $staff);

    expect($customer->fresh()->user_type)->toBe(UserType::Vendor);
})->with([SystemRole::SuperAdmin, SystemRole::Manager]);

it('refuses other staff roles', function (SystemRole $role) {
    $customer = User::factory()->create();
    $staff = SystemUser::factory()->withRole($role)->create();

    expect(fn () => app(ChangeUserType::class)->handle($customer, UserType::ApiUser, $staff))
        ->toThrow(AuthorizationException::class);

    expect($customer->fresh()->user_type)->toBe(UserType::Subscriber);
})->with([SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

it('refuses disabled staff even with the permission', function () {
    $customer = User::factory()->create();
    $staff = SystemUser::factory()->disabled()->withRole(SystemRole::Manager)->create();

    expect(fn () => app(ChangeUserType::class)->handle($customer, UserType::Affiliate, $staff))
        ->toThrow(AuthorizationException::class);
});
