<?php

namespace App\Services\Providers\Data;

/**
 * A provider HTTP response that did arrive. The client does not interpret it:
 * the adapter maps documented responses to definite outcomes and treats
 * everything else (including 5xx and non-JSON bodies) as unknown.
 */
final readonly class ProviderHttpResponse
{
    /** @param  array<mixed>|null  $json  decoded body, null when not a JSON object/array */
    public function __construct(
        public int $status,
        public ?array $json,
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function serverError(): bool
    {
        return $this->status >= 500;
    }
}
