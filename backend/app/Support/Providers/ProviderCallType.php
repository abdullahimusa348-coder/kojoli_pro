<?php

namespace App\Support\Providers;

/** Kind of provider HTTP call: purchases are never retried automatically; queries may be, when the caller allows it. */
enum ProviderCallType: string
{
    case Purchase = 'purchase';
    case Query = 'query';
    case Other = 'other';
}
