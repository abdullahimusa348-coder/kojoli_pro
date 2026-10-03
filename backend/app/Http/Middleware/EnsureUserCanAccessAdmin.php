<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCanAccessAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canAccessAdmin(), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
