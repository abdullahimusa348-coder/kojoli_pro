<?php

namespace App\Services\Payments\Data;

use App\Support\Payments\WebhookOutcome;

/** How an inbound webhook was handled: the HTTP status to answer with and the stored outcome (null when nothing was stored). */
final readonly class WebhookResult
{
    public function __construct(
        public int $httpStatus,
        public ?WebhookOutcome $outcome,
        public bool $duplicate = false,
    ) {}
}
