<?php

use App\Http\Middleware\EnsureEmailIsVerifiedIfRequired;
use App\Http\Middleware\EnsureSystemUserIsActive;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RefuseIdentityNumberSearch;
use App\Http\Middleware\UseAdminSession;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'staff.active' => EnsureSystemUserIsActive::class,
            'verified.optional' => EnsureEmailIsVerifiedIfRequired::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);

        // Admin area gets its own session cookie/table; must run before the session starts.
        $middleware->web(prepend: [UseAdminSession::class]);

        // Sign out web sessions of customer accounts that were disabled after login.
        $middleware->web(append: [EnsureUserIsActive::class]);

        // The ordinary purchase search never takes a NIN or BVN (Phase 11 CP3): refused before authentication,
        // so a signed-out visitor's intended URL never holds one.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: RefuseIdentityNumberSearch::class);

        $middleware->redirectGuestsTo(fn (Request $request) => UseAdminSession::isAdminRequest($request)
            ? route('admin.login')
            : route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => UseAdminSession::isAdminRequest($request)
            ? route('admin.dashboard')
            : route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Provider credential fields are write-only: never flash them back as old input.
        // A NIN or BVN (Phase 11 CP3) is never flashed either, so it never reaches the session.
        $exceptions->dontFlash(['credentials', 'identity_number']);
    })->create();
