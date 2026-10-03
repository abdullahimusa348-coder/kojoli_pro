<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\ConfirmPasswordRequest;
use App\Models\User;
use App\Support\Customer\CustomerDashboard;
use App\Support\Customer\CustomerSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Customer Security page: password change (PasswordController), email
 * verification status, and the customer's own browser sessions and app
 * tokens. Every action is limited to the signed-in customer's own records.
 */
class SecurityController extends Controller
{
    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('user.security', [
            'user' => $user,
            'emailStatus' => CustomerDashboard::for($user)['emailStatus'],
            'verificationRequired' => User::emailVerificationRequired(),
            'sessionsAvailable' => CustomerSessions::available(),
            'sessions' => CustomerSessions::for($user, $request->session()->getId()),
            // Only safe columns; the token value and hash are never loaded into the view.
            'tokens' => $user->tokens()->latest('id')->get(['id', 'name', 'created_at', 'last_used_at', 'expires_at']),
        ]);
    }

    /** Log out one other browser (no password needed). */
    public function destroySession(Request $request, string $session): RedirectResponse
    {
        $id = CustomerSessions::findId($request->user(), $session);

        // Unknown, someone else's, or the current session: the current one is ended with Log out.
        abort_if($id === null || hash_equals($id, $request->session()->getId()), 404);

        CustomerSessions::delete($request->user(), $id);

        return redirect()->route('security')->with('status', 'Browser logged out.');
    }

    /** Log out every other browser (current password required). */
    public function logoutOtherSessions(ConfirmPasswordRequest $request): RedirectResponse
    {
        // Rehashes the password so other sessions and "remember me" cookies stop working.
        Auth::guard('web')->logoutOtherDevices($request->validated('current_password'));
        $count = CustomerSessions::deleteOthers($request->user(), $request->session()->getId());

        return redirect()->route('security')->with('status', $count === 1 ? '1 other browser logged out.' : "{$count} other browsers logged out.");
    }

    /** Revoke one app token (no password needed). */
    public function destroyToken(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->firstOrFail()->delete();

        return redirect()->route('security')->with('status', 'App access revoked.');
    }

    /** Revoke every app token (current password required). */
    public function destroyAllTokens(ConfirmPasswordRequest $request): RedirectResponse
    {
        $count = $request->user()->tokens()->delete();

        return redirect()->route('security')->with('status', $count === 1 ? '1 app revoked.' : "{$count} apps revoked.");
    }
}
