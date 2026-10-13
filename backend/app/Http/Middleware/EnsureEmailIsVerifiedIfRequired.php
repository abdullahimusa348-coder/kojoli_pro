<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's `verified` middleware, active only when
 * nadabo.require_email_verification is on. Off by default.
 */
class EnsureEmailIsVerifiedIfRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! User::emailVerificationRequired()) {
            return $next($request);
        }

        return app(EnsureEmailIsVerified::class)->handle($request, $next);
    }
}
