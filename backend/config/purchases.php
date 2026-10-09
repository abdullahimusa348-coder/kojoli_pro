<?php

/*
| Purchase engine (Phase 10). Technical timing only; business limits come from
| the Pricing Engine (pricing.max_amount_kobo) and the catalog.
*/

return [

    /*
    | Provider re-check schedule for purchases with an unclear outcome
    | (minutes after the unclear attempt), then every `recheck_every_minutes`.
    | A purchase without a definite outcome after `review_after_hours` moves
    | to review. Unclear outcomes are never refunded or failed over.
    */
    'recheck_schedule_minutes' => [2, 5, 15, 60],
    'recheck_every_minutes' => 360,
    'review_after_hours' => 24,

    // A "started" attempt older than this (an interrupted provider call) is
    // treated as unknown and re-checked; it is never retried.
    'stale_attempt_minutes' => 10,

    // purchases:reconcile (every five minutes): purchases checked per run, and
    // the minimum age of a pending purchase that has no check scheduled yet.
    'reconcile_batch' => 50,
    'reconcile_min_age_minutes' => 2,

    // A pending or review purchase whose next check has been due for longer
    // than this is "overdue" in the admin monitoring: reconciliation runs every
    // five minutes, so an overdue check means the scheduler is not running or
    // is falling behind.
    'overdue_after_minutes' => 15,

    // Customer result page: a pending purchase made less than
    // `customer_refresh_window_minutes` ago reloads itself every
    // `customer_refresh_seconds` (the customer can stop it; the manual
    // Refresh link stays). Under review and final purchases never do.
    'customer_refresh_seconds' => 30,
    'customer_refresh_window_minutes' => 10,

    /*
    | NIN and BVN purchases (Phase 11 CP3): the exact sentence the customer
    | must accept on the confirmation page before a NIN or BVN is submitted.
    | Approved wording; no other legal text is shown. A service without its
    | sentence cannot be bought (its Buy page says it is not available).
    */
    'identity_consent' => [
        'nin' => 'I confirm that the NIN I entered is correct and I consent to its submission for this service.',
        'bvn' => 'I confirm that the BVN I entered is correct and I consent to its submission for this service.',
    ],

];
