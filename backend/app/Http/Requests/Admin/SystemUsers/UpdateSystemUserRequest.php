<?php

namespace App\Http\Requests\Admin\SystemUsers;

/** Password is optional on edit: blank keeps the current password. */
class UpdateSystemUserRequest extends SystemUserRequest
{
    protected function passwordRequired(): bool
    {
        return false;
    }
}
