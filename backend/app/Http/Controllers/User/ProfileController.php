<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Support\Customer\CustomerDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('user.profile', [
            'user' => $user,
            // Same email-verification wording as the dashboard.
            'emailStatus' => CustomerDashboard::for($user)['emailStatus'],
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        // Name and email only: phone, type and status are never changed by the customer.
        $user->fill($request->safe()->only(['name', 'email']));

        $emailChanged = $user->isDirty('email');
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            // No-op unless email verification is switched on.
            $user->sendEmailVerificationNotification();
        }

        return redirect()->route('profile.edit')->with('status', 'profile-updated');
    }
}
