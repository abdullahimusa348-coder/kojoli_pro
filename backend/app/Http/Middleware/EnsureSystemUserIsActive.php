<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the admin session of staff who were disabled (or lost admin access)
 * after signing in.
 */
class EnsureSystemUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $staff = Auth::guard('admin')->user();

        if ($staff === null || $staff->canAccessAdmin()) {
            return $next($request);
        }

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')
            ->withErrors(['email' => 'Your staff access has been disabled.']);
    }
}
