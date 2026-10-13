<?php

namespace App\Support\Referrals;

use App\Models\Commission;
use App\Models\SystemUser;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;

/**
 * The one-time token of a commission's Reverse or Cancel form (Phase 12): an
 * encrypted payload bound to the commission, the action, the staff member
 * and its issue time, carrying a new UUID that becomes the action's
 * idempotency key (unique, so a token records at most one action). Valid for
 * VALID_SECONDS. A token that is missing, does not decrypt, was altered, was
 * issued for another commission, action or staff member, or has expired
 * opens to nothing (fail closed): it can never act. The action type always
 * comes from the route, never from the form.
 */
final class CommissionActionToken
{
    /** A form can be submitted for 30 minutes after it was shown. */
    public const VALID_SECONDS = 1800;

    public static function issue(Commission $commission, CommissionActionType $type, SystemUser $staff): string
    {
        return Crypt::encryptString(json_encode([
            'commission' => $commission->id, 'action' => $type->value, 'staff' => $staff->id, 'key' => (string) Str::uuid(),
            'issued_at' => now()->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /** The token's one-time key (a UUID) when it was issued for this commission, action and staff member and is still valid; otherwise null. */
    public static function open(mixed $token, Commission $commission, CommissionActionType $type, SystemUser $staff): ?string
    {
        if (! is_string($token) || $token === '' || strlen($token) > 4096) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }
        $now = now()->getTimestamp();
        if (! is_array($data) || ($data['commission'] ?? null) !== $commission->id || ($data['action'] ?? null) !== $type->value
            || ($data['staff'] ?? null) !== $staff->id || ! is_string($data['key'] ?? null) || ! Str::isUuid($data['key'])
            || ! is_int($data['issued_at'] ?? null) || $data['issued_at'] > $now || $now - $data['issued_at'] >= self::VALID_SECONDS) {
            return null;
        }

        return $data['key'];
    }
}
