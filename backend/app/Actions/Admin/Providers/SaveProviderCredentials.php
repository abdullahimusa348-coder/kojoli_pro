<?php

namespace App\Actions\Admin\Providers;

use App\Models\Provider;
use App\Models\ProviderCredential;
use App\Models\ProviderCredentialChange;
use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use App\Support\Providers\CredentialKey;
use Illuminate\Support\Facades\DB;

/**
 * Write-only provider credentials (providers.credentials). Values are stored
 * encrypted with only a last-four hint; blank inputs keep the current value;
 * every set, replacement and clear is recorded without the value. Values are
 * never returned, logged or put in messages.
 */
class SaveProviderCredentials
{
    public function __construct(private ProviderRules $rules) {}

    /**
     * @param  array<string, ?string>  $values  keyed by CredentialKey value; blank entries are ignored
     * @return int number of credentials set or replaced
     */
    public function handle(Provider $provider, array $values, SystemUser $actor): int
    {
        $this->rules->authorize($actor, SystemPermission::ProvidersCredentials);

        return DB::transaction(function () use ($provider, $values, $actor) {
            $count = 0;
            foreach (CredentialKey::cases() as $key) {
                $value = $values[$key->value] ?? null;
                if (! is_string($value) || $value === '') {
                    continue;
                }

                $credential = ProviderCredential::where('provider_id', $provider->id)->where('key', $key->value)->lockForUpdate()->first();
                $action = $credential === null ? 'set' : 'replaced';
                $credential ??= (new ProviderCredential)->forceFill(['provider_id' => $provider->id, 'key' => $key]);
                $credential->forceFill(['value' => $value, 'hint' => ProviderCredential::hintFor($value), 'updated_by' => $actor->id])->save();
                self::record($provider, $key, $action, $actor);
                $count++;
            }

            return $count;
        });
    }

    /** Removes one stored credential. Returns false when nothing was stored. */
    public function clear(Provider $provider, CredentialKey $key, SystemUser $actor): bool
    {
        $this->rules->authorize($actor, SystemPermission::ProvidersCredentials);

        return DB::transaction(function () use ($provider, $key, $actor) {
            $deleted = ProviderCredential::where('provider_id', $provider->id)->where('key', $key->value)->delete();
            if ($deleted === 0) {
                return false;
            }
            self::record($provider, $key, 'cleared', $actor);

            return true;
        });
    }

    private static function record(Provider $provider, CredentialKey $key, string $action, SystemUser $actor): void
    {
        (new ProviderCredentialChange)->forceFill([
            'provider_id' => $provider->id,
            'key' => $key,
            'action' => $action,
            'changed_by' => $actor->id,
        ])->save();
    }
}
