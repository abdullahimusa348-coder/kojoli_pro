<?php

namespace App\Support\Validation;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/** Field rules shared by registration, profile and (later) API requests. */
class AccountRules
{
    /** Nigerian mobile number after normalisation, e.g. 08012345678. */
    public const PHONE_REGEX = '/^0[789][01]\d{8}$/';

    /** @return list<mixed> */
    public static function name(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /** @return list<mixed> */
    public static function email(?User $ignore = null): array
    {
        return ['required', 'string', 'lowercase', 'email', 'max:255', self::unique('email', $ignore)];
    }

    /** @return list<mixed> */
    public static function phone(?User $ignore = null): array
    {
        return ['required', 'string', 'regex:'.self::PHONE_REGEX, self::unique('phone', $ignore)];
    }

    /** Lower-case the email and normalise the phone before validation, so uniqueness checks match stored values. */
    public static function normalise(array $input): array
    {
        $out = [];
        if (isset($input['email']) && is_string($input['email'])) {
            $out['email'] = mb_strtolower(trim($input['email']));
        }
        if (isset($input['phone']) && is_string($input['phone'])) {
            $out['phone'] = User::normalizePhone($input['phone']);
        }

        return $out;
    }

    private static function unique(string $column, ?User $ignore): Unique
    {
        $rule = Rule::unique(User::class, $column);

        return $ignore ? $rule->ignore($ignore->id) : $rule;
    }
}
