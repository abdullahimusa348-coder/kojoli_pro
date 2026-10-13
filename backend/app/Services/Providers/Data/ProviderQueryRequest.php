<?php

namespace App\Services\Providers\Data;

/** A status query for an earlier purchase call, identified by our request reference and the provider's reference when known. */
final readonly class ProviderQueryRequest
{
    public function __construct(
        public string $requestReference,
        public ?string $providerReference,
        public string $serviceSlug,
    ) {}
}
