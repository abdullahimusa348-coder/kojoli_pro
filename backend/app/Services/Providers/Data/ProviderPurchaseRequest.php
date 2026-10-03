<?php

namespace App\Services\Providers\Data;

/**
 * What the engine asks a provider to deliver. Built by the purchase engine
 * from the purchase and attempt snapshots; adapters translate it into the
 * provider's documented request. Integer kobo only.
 */
final readonly class ProviderPurchaseRequest
{
    public function __construct(
        public string $requestReference,
        public string $serviceSlug,
        public ?string $network,
        public ?string $providerPlanCode,
        public string $recipient,
        public int $amountKobo,
        public ?int $faceValueKobo = null,
    ) {}

    /** @return array<string, mixed> never exposes the recipient */
    public function __debugInfo(): array
    {
        return ['requestReference' => $this->requestReference, 'serviceSlug' => $this->serviceSlug, 'network' => $this->network,
            'providerPlanCode' => $this->providerPlanCode, 'recipient' => '[redacted]', 'amountKobo' => $this->amountKobo, 'faceValueKobo' => $this->faceValueKobo];
    }
}
