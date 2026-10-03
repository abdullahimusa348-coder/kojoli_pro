<?php

namespace App\Providers;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(8)->letters()->mixedCase()->numbers());

        // Super admins pass every authorization check; everyone else goes through permissions/policies.
        Gate::before(fn (User $user) => $user->hasRole(RolesAndPermissionsSeeder::SUPER_ADMIN) ? true : null);
    }
}
