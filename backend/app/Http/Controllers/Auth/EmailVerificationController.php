<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Laravel's standard verification flow (notice, verify link, resend). Only
 * enforced when nadabo.require_email_verification is on; otherwise the
 * notice and resend routes simply return to the dashboard.
 */
class EmailVerificationController extends Controller
{
    public function notice(Request $request): RedirectResponse|View
    {
        if (! User::emailVerificationRequired() || $request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        return view('auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        // Marks the email verified and fires the Verified event (once).
        $request->fulfill();

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }

    public function send(Request $request): RedirectResponse
    {
        if (! User::emailVerificationRequired() || $request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}
