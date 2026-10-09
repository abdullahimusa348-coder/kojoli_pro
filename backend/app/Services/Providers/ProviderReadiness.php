<?php

namespace App\Services\Providers;

use App\Support\Providers\CredentialKey;

/**
 * Whether a provider's adapter can be used now, for admin display and the
 * runnable-route check. Holds names and states only, never credential values.
 */
final readonly class ProviderReadiness
{
    /**
     * @param  list<string>  $services  slugs of the services the adapter supports
     * @param  list<CredentialKey>  $credentialKeys  credentials the adapter needs
     * @param  list<CredentialKey>  $missingCredentialKeys  of those, the ones not set
     * @param  list<string>  $problems  why the adapter cannot be used now; empty when it can
     */
    public function __construct(
        public bool $installed,
        public ?string $label,
        public array $services,
        public array $credentialKeys,
        public array $missingCredentialKeys,
        public ?bool $baseUrlAllowed,
        public bool $canQuery,
        public array $problems,
    ) {}

    public function ready(): bool
    {
        return $this->installed && $this->problems === [];
    }
}
