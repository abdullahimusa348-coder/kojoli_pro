<?php

namespace App\Services\Providers;

/**
 * Whether purchases can use a plan route now: Phase 7 eligibility plus the
 * provider's adapter. $reasons lists everything that stops it (the Phase 7
 * reasons first, then the adapter ones); empty when the route is runnable.
 */
final readonly class RouteReadiness
{
    /** @param  list<string>  $reasons */
    public function __construct(
        public RouteCandidate $candidate,
        public array $reasons,
    ) {}

    public function runnable(): bool
    {
        return $this->reasons === [];
    }
}
