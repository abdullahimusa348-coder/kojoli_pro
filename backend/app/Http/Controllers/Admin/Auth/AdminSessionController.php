<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Actions\Auth\AuthenticateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Staff sign-in. Uses the same users table and session guard as customers,
 * but only lets in accounts holding the admin.access permission.
 */
class AdminSessionController extends Controller
{
    public function create(): View
    {
        return view('admin.auth.login');
    }

    public function store(LoginRequest $request, AuthenticateUser $authenticate): RedirectResponse
    {
        $user = $authenticate->handle($request->validated('login'), $request->validated('password'), (string) $request->ip());

        if (! $user->canAccessAdmin()) {
            throw ValidationException::withMessages(['login' => trans('auth.failed')]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard', absolute: false));
    }
}
