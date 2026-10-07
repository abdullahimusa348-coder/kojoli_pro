<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Support\Referrals\ReferralCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(Request $request): View
    {
        // A shared link's ?ref= only fills in the optional referral code field of this form, and only when it is
        // 1 to 20 letters or digits: no lookup, cookie or session storage (the code is checked when the form is sent).
        $ref = $request->query('ref');

        return view('auth.register', [
            'referralCode' => is_string($ref) && preg_match('/\A[A-Za-z0-9]{1,20}\z/', $ref) === 1 ? ReferralCodes::normalise($ref) : null,
        ]);
    }

    public function store(RegisterRequest $request, RegisterUser $register): RedirectResponse
    {
        $user = $register->handle($request->safe()->except('referral_code'), $request->referralCode());

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
