<?php

namespace App\Services\Providers\Data;

/**
 * What the engine asks a provider to deliver. Built by the purchase engine
 * from the purchase and attempt snapshots; adapters translate it into the
 * provider's documented request. Integer kobo only.
 * recipientType says what the recipient is (Phase 11): 'phone' (canonical
 * phone number, the Phase 10 meaning and the default), 'nin' or 'bvn' (the
 * 11-digit number, decrypted in memory for this call only), or 'none' (Exam
 * PIN, CP4: there is no recipient and recipient is the empty string; an
 * adapter sends no recipient). The recipient is never exposed in debug output.
 */
final readonly class ProviderPurchaseRequest
{
    public function __construct(
        public string $requestReference,
        public string $serviceSlug,
        public ?string $network,
        public ?string $providerPlanCode,
        #[\SensitiveParameter] public string $recipient,
        public int $amountKobo,
        public ?int $faceValueKobo = null,
        public string $recipientType = 'phone',
    ) {}

    /** @return array<string, mixed> never exposes the recipient */
    public function __debugInfo(): array
    {
        return ['requestReference' => $this->requestReference, 'serviceSlug' => $this->serviceSlug, 'network' => $this->network,
            'providerPlanCode' => $this->providerPlanCode, 'recipient' => '[redacted]', 'amountKobo' => $this->amountKobo, 'faceValueKobo' => $this->faceValueKobo,
            'recipientType' => $this->recipientType];
    }
}
