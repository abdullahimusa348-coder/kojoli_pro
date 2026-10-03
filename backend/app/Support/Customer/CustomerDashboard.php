<?php

namespace App\Support\Customer;

use App\Models\User;

/**
 * Data for the customer dashboard home. Account information only: no wallet,
 * money, transaction or service figures until those modules exist.
 */
class CustomerDashboard
{
    /**
     * @return array{
     *     user: User,
     *     emailStatus: array{label: string, tone: string},
     *     showVerificationPrompt: bool,
     *     shortcuts: list<array{item: CustomerNav, description: string}>
     * }
     */
    public static function for(User $user): array
    {
        $required = User::emailVerificationRequired();
        $verified = $user->hasVerifiedEmail();

        return [
            'user' => $user,
            'emailStatus' => match (true) {
                $verified => ['label' => 'Verified', 'tone' => 'good'],
                $required => ['label' => 'Not verified', 'tone' => 'warn'],
                default => ['label' => 'Not required', 'tone' => 'neutral'],
            },
            // Only when verification is switched on and this email is not verified yet.
            'showVerificationPrompt' => $required && ! $verified,
            'shortcuts' => [
                ['item' => CustomerNav::Account, 'description' => 'View your details and update your name or email.'],
                ['item' => CustomerNav::Security, 'description' => 'Change your password and keep your account safe.'],
            ],
        ];
    }
}
