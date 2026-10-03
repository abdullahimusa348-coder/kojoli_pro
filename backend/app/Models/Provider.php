<?php

namespace App\Models;

use App\Support\Providers\CredentialKey;
use App\Support\Providers\ProviderStatus;
use Database\Factories\ProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An external provider (system infrastructure, independent of the catalog).
 * Supports services through ProviderService rows and is attached to plans by
 * ordered PlanProviderRoute rows. Holds no secrets: credentials are separate,
 * encrypted and write-only. Configuration status is derived, never stored.
 * Code and status change only through the provider actions.
 */
class Provider extends Model
{
    /** @use HasFactory<ProviderFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'driver', 'settings', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ProviderStatus::class,
            'settings' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<ProviderService, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(ProviderService::class);
    }

    /** @return HasMany<PlanProviderRoute, $this> */
    public function routes(): HasMany
    {
        return $this->hasMany(PlanProviderRoute::class);
    }

    /** @return HasMany<ProviderCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(ProviderCredential::class);
    }

    /** @return HasMany<ProviderCredentialChange, $this> */
    public function credentialChanges(): HasMany
    {
        return $this->hasMany(ProviderCredentialChange::class);
    }

    public function baseUrl(): ?string
    {
        return $this->settings['base_url'] ?? null;
    }

    /** @return list<CredentialKey> credential keys this provider declares as required */
    public function requiredCredentialKeys(): array
    {
        return array_values(array_filter(array_map(
            fn ($value) => CredentialKey::tryFrom((string) $value),
            $this->settings['required_credentials'] ?? [],
        )));
    }

    /** @return list<CredentialKey> required keys that have no stored value */
    public function missingCredentialKeys(): array
    {
        $set = $this->credentials->map(fn (ProviderCredential $c) => $c->key)->all();

        return array_values(array_filter($this->requiredCredentialKeys(), fn (CredentialKey $k) => ! in_array($k, $set, true)));
    }

    /** Derived: at least one required credential is declared and every declared one is set. */
    public function isConfigured(): bool
    {
        return $this->requiredCredentialKeys() !== [] && $this->missingCredentialKeys() === [];
    }

    public function configurationLabel(): string
    {
        if ($this->requiredCredentialKeys() === []) {
            return 'Not configured (no required credentials chosen)';
        }
        $missing = $this->missingCredentialKeys();

        return $missing === []
            ? 'Configured'
            : 'Not configured (missing: '.implode(', ', array_map(fn (CredentialKey $k) => $k->label(), $missing)).')';
    }

    public function scopeOrdered($query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
