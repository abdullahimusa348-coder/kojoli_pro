<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Actions\Admin\Auth\AuthenticateSystemUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Staff sign-in on the `admin` guard (system_users table). Customer accounts
 * cannot sign in here, and staff accounts cannot sign in on the customer side.
 */
class AdminSessionController extends Controller
{
    public function create(): View
    {
        return view('admin.auth.login');
    }

    public function store(StaffLoginRequest $request, AuthenticateSystemUser $authenticate): RedirectResponse
    {
        $staff = $authenticate->handle($request->validated('email'), $request->validated('password'), (string) $request->ip());

        Auth::guard('admin')->login($staff);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();

        // Only the admin session is affected; the customer session uses a different cookie.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
