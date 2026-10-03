<?php

namespace App\Services\Payments\Data;

/** Raw inbound webhook as received. Headers are used for authentication only and are never stored. */
final readonly class WebhookRequest
{
    /** @param  array<string, string>  $headers  lower-cased names */
    public function __construct(
        public string $body,
        public array $headers,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
