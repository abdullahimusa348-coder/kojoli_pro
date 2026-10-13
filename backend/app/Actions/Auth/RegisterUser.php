<?php

namespace App\Actions\Auth;

use App\Models\Referral;
use App\Models\User;
use App\Services\Referrals\SignupReferrer;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a customer account. Shared by web registration and (later) the mobile API.
 * Public sign-ups are always Subscribers; other tiers are assigned by an admin.
 *
 * Referral attribution (Phase 12) happens here and nowhere else, so a customer
 * gets a referrer only when their account is created. A referral code is
 * accepted only from web registration, as its own argument. In one
 * transaction the code's owner is read with a shared lock and checked again
 * (an Active Subscriber, Vendor or Affiliate): disabling the owner or
 * changing their type either waits for this signup or is seen by it. Then
 * the customer is created and the permanent link inserted. A code that can
 * no longer be used refuses the whole signup with the same neutral message
 * as any other invalid code, and nothing is created. Registered is fired
 * after the commit.
 */
class RegisterUser
{
    private const ATTEMPTS = 3;

    /**
     * @param  array{name: string, email: string, phone: string, password: string}  $data
     */
    public function handle(array $data, ?string $referralCode = null): User
    {
        $user = DB::transaction(function () use ($data, $referralCode) {
            $referrer = $referralCode === null ? null
                : (SignupReferrer::find($referralCode, sharedLock: true) ?? throw ValidationException::withMessages(['referral_code' => SignupReferrer::INVALID]));

            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'],
            ]);
            $user->user_type = UserType::Subscriber;
            $user->status = UserStatus::Active;
            $user->save();

            if ($referrer !== null) {
                (new Referral)->forceFill(['referrer_id' => $referrer->id, 'referred_user_id' => $user->id])->save();
            }

            return $user;
        }, self::ATTEMPTS);

        event(new Registered($user));

        return $user;
    }
}
