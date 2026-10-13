<?php

/*
| Provider execution engine (Phase 10). Business data (providers, supported
| services, plan routes, credentials) lives in the database and is managed in
| the admin area; this file only holds code-level wiring and technical limits.
*/

return [

    /*
    | Provider adapters available to the purchase engine, keyed by driver name
    | (matched against providers.driver). Each class implements
    | App\Services\Providers\Contracts\ProviderAdapter and declares its own
    | endpoints, hosts, credentials and supported services. Phase 10 Step 1
    | ships with none: real adapters are added only after their official API
    | documentation has been verified.
    */
    'drivers' => [],

    'http' => [
        'connect_timeout' => 5,    // seconds
        'default_timeout' => 30,   // seconds, when an adapter declares none
        'min_timeout' => 5,        // adapter timeouts are clamped to this range
        'max_timeout' => 60,
        'query_retries' => 2,      // extra attempts for status queries that allow it; purchase calls are never retried
        'retry_sleep_ms' => 200,
    ],

];
