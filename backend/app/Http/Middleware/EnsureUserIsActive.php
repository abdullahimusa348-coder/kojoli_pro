<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends access for customer accounts disabled after they signed in.
 * Web: logs out and returns to the login page. API: 403 JSON.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // Default guard: web session on web routes, Sanctum token on API routes.
        $user = $request->user();

        if (! $user instanceof User || $user->isActive()) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'This account has been disabled.'], Response::HTTP_FORBIDDEN);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->withErrors(['login' => 'This account has been disabled. Please contact support.']);
    }
}
