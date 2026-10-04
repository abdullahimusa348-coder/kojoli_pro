<?php

namespace App\Services\Providers\Data;

use App\Support\Providers\ProviderOutcome;
use JsonSerializable;
use LogicException;

/**
 * Normalized outcome of one provider purchase or query. Messages and error
 * codes are made safe on construction: trimmed, length-limited and with long
 * digit runs (phone, meter, account or card numbers) masked. Adapters must
 * still never put credentials or tokens into them.
 * Only a succeeded outcome may carry result fields (Phase 11 CP2: what the
 * provider delivered, e.g. NIN/BVN result text); debug output and JSON show
 * only how many fields there are, never their values.
 */
final readonly class ProviderResult implements JsonSerializable
{
    public ?string $message;

    public ?string $errorCode;

    private function __construct(
        public ProviderOutcome $outcome,
        public ?string $providerReference,
        ?string $message,
        ?string $errorCode,
        public ?ProviderResultFields $fields = null,
    ) {
        if ($fields !== null && $outcome !== ProviderOutcome::Succeeded) {
            throw new LogicException('Only a succeeded outcome carries result fields.');
        }
        $this->message = self::safe($message, 255);
        $this->errorCode = self::safe($errorCode, 50);
    }

    public static function succeeded(?string $providerReference = null, ?string $message = null, ?ProviderResultFields $fields = null): self
    {
        return new self(ProviderOutcome::Succeeded, self::reference($providerReference), $message, null, $fields);
    }

    public static function failedDefinite(?string $errorCode = null, ?string $message = null, ?string $providerReference = null): self
    {
        return new self(ProviderOutcome::FailedDefinite, self::reference($providerReference), $message, $errorCode);
    }

    public static function unknown(?string $errorCode = null, ?string $message = null, ?string $providerReference = null): self
    {
        return new self(ProviderOutcome::Unknown, self::reference($providerReference), $message, $errorCode);
    }

    /** Masks digit runs of 7 or more (keeping the last 3) and limits the length. */
    public static function safe(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim(preg_replace('/\s+/', ' ', $text));
        $text = preg_replace_callback('/\d{7,}/', fn ($m) => str_repeat('•', strlen($m[0]) - 3).substr($m[0], -3), $text);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** @return array<string, mixed> never the result values */
    public function jsonSerialize(): array
    {
        return ['outcome' => $this->outcome->value, 'providerReference' => $this->providerReference, 'message' => $this->message,
            'errorCode' => $this->errorCode, 'fields' => $this->fields?->count()];
    }

    /** @return array<string, mixed> never the result values */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    private static function reference(?string $reference): ?string
    {
        $reference = $reference === null ? null : trim($reference);

        return $reference === '' || $reference === null ? null : mb_substr($reference, 0, 100);
    }
}
