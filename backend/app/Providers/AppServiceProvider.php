<?php

namespace App\Providers;

use App\Models\SystemUser;
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

        // Active Super Admin staff pass every authorization check; everyone else goes through
        // permissions/policies. Customers (User) never hold roles, so this never applies to them.
        Gate::before(fn ($user) => $user instanceof SystemUser && $user->isActive() && $user->isSuperAdmin() ? true : null);
    }
}
