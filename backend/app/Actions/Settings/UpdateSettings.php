<?php

namespace App\Actions\Settings;

use App\Models\SystemUser;
use App\Services\Settings\SettingsStore;
use App\Support\Enums\SystemPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Saves a batch of settings changes made by a staff member, all or nothing.
 * Requires settings.update even when called outside the HTTP route.
 */
class UpdateSettings
{
    public function __construct(private SettingsStore $settings) {}

    /** @param  array<string, mixed>  $values  setting key => submitted value */
    public function handle(array $values, SystemUser $actor): int
    {
        if (! $actor->isActive() || ! $actor->can(SystemPermission::SettingsUpdate->value)) {
            throw new AuthorizationException('You are not allowed to change settings.');
        }

        $changed = 0;

        DB::transaction(function () use ($values, $actor, &$changed) {
            foreach ($values as $key => $value) {
                if (! $this->settings->has($key)) {
                    continue;
                }
                $this->settings->set($key, $value, $actor);
                $changed++;
            }
        });

        return $changed;
    }
}
