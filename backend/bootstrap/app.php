<?php

use App\Http\Middleware\EnsureUserCanAccessAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
            'admin' => EnsureUserCanAccessAdmin::class,
        ]);

        // Sign out web sessions of accounts that were disabled after login.
        $middleware->web(append: [EnsureUserIsActive::class]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin', 'admin/*')
            ? route('admin.login')
            : route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
