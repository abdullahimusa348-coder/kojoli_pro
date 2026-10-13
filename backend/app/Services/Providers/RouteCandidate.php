<?php

namespace App\Services\Providers;

use App\Models\PlanProviderRoute;

/** One route of a plan as the resolver sees it: eligible, or skipped with the reasons why. */
final readonly class RouteCandidate
{
    /** @param  list<string>  $reasons */
    public function __construct(
        public PlanProviderRoute $route,
        public bool $eligible,
        public array $reasons,
    ) {}
}
