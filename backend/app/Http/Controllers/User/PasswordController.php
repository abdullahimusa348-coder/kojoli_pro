<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class PasswordController extends Controller
{
    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        $password = $request->validated('password');
        $request->user()->update(['password' => $password]);

        // Sign out other browsers/devices and API tokens that were using the old password.
        Auth::logoutOtherDevices($password);
        $request->user()->tokens()->delete();
        $request->session()->regenerate();

        return redirect()->route('profile.edit')->with('status', 'password-updated');
    }
}
