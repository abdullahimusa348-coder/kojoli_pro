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

];
