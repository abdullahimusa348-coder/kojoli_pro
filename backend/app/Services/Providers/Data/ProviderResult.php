<?php

namespace App\Services\Providers\Data;

use App\Support\Providers\ProviderOutcome;

/**
 * Normalized outcome of one provider purchase or query. Messages and error
 * codes are made safe on construction: trimmed, length-limited and with long
 * digit runs (phone, meter, account or card numbers) masked. Adapters must
 * still never put credentials or tokens into them.
 */
final readonly class ProviderResult
{
    public ?string $message;

    public ?string $errorCode;

    private function __construct(
        public ProviderOutcome $outcome,
        public ?string $providerReference,
        ?string $message,
        ?string $errorCode,
    ) {
        $this->message = self::safe($message, 255);
        $this->errorCode = self::safe($errorCode, 50);
    }

    public static function succeeded(?string $providerReference = null, ?string $message = null): self
    {
        return new self(ProviderOutcome::Succeeded, self::reference($providerReference), $message, null);
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

    private static function reference(?string $reference): ?string
    {
        $reference = $reference === null ? null : trim($reference);

        return $reference === '' || $reference === null ? null : mb_substr($reference, 0, 100);
    }
}
