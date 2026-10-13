<?php

namespace App\Actions\Admin\Providers;

use App\Models\Provider;
use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use App\Support\Providers\ProviderStatus;
use Illuminate\Support\Str;

/**
 * Creates or updates a provider. The code is generated from the name on
 * create and never changed. New providers start inactive. Status has its own
 * action; credentials are handled separately and never pass through here.
 */
class SaveProvider
{
    public function __construct(private ProviderRules $rules) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, SystemUser $actor): Provider
    {
        $this->rules->authorize($actor, SystemPermission::ProvidersCreate);

        $provider = new Provider($this->attributes($data));
        $provider->code = self::codeFor($data['name']);
        $provider->status = ProviderStatus::Inactive;
        $provider->save();

        return $provider;
    }

    /** @param  array<string, mixed>  $data */
    public function update(Provider $provider, array $data, SystemUser $actor): Provider
    {
        $this->rules->authorize($actor, SystemPermission::ProvidersUpdate);

        $provider->fill($this->attributes($data))->save();

        return $provider;
    }

    public static function codeFor(string $name): string
    {
        return Str::slug($name);
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        $settings = array_filter([
            'base_url' => $data['base_url'] ?? null,
            'required_credentials' => array_values($data['required_credentials'] ?? []) ?: null,
        ], fn ($v) => $v !== null);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'driver' => $data['driver'] ?? null,
            'settings' => $settings ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
