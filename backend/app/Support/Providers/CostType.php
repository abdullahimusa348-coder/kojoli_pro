<?php

namespace App\Support\Providers;

/** Provider cost on a route: a fixed kobo amount (fixed plans) or a discount off face value (variable plans). */
enum CostType: string
{
    case Fixed = 'fixed';
    case Percent = 'percent';
}
