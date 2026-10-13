<?php

/*
| Payment gateway engine (Phase 9). Business limits (funding minimum and
| maximum, pending expiry, live switch) live in the Settings Store; this file
| only holds code-level wiring and technical limits.
*/

return [

    /*
    | Gateway adapters available to admins, keyed by driver name. Each class
    | implements App\Services\Payments\Contracts\PaymentGateway and declares its
    | own endpoints, credentials and allowed hosts. Phase 9 Step 1 ships with
    | none: the Monnify and Aspfiy adapters are added in later steps, only
    | after their official documentation has been verified.
    */
    'drivers' => [],

    'http' => [
        'connect_timeout' => 5,   // seconds
        'timeout' => 15,          // seconds
        'verify_retries' => 2,    // extra attempts for safe (read-only) verification calls only
        'retry_sleep_ms' => 200,
    ],

    'webhooks' => [
        'max_bytes' => 65536,         // 64 KB; larger bodies are rejected unread
        'payload_retention_days' => 180,
    ],

    // Pending payments younger than this are left alone by reconciliation
    // (the customer may still be on the gateway's page).
    'reconcile_min_age_minutes' => 2,

    // An expired pending payment is failed only after this extra grace period
    // AND a server-side check that still finds it unpaid. A payment that
    // turns out paid later goes to review, never straight to a credit.
    'expiry_grace_minutes' => 30,

    // Payments checked per reconciliation run (keeps each cron run short).
    'reconcile_batch' => 50,

];
