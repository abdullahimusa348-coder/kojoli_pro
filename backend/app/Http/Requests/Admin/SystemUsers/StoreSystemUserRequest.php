<?php

namespace App\Http\Requests\Admin\SystemUsers;

use App\Support\Enums\UserStatus;
use Illuminate\Validation\Rule;

class StoreSystemUserRequest extends SystemUserRequest
{
    protected function passwordRequired(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'status' => ['required', Rule::enum(UserStatus::class)],
        ];
    }
}
