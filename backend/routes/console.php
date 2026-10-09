<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delete expired Sanctum API tokens (needs the scheduler cron at deployment).
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Payments: recheck pending payments with their gateway, and clear old webhook payloads.
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('payments:prune-webhooks')->daily();

// Purchases: re-check purchases with an unclear provider outcome.
Schedule::command('purchases:reconcile')->everyFiveMinutes()->withoutOverlapping(10);

// Integrity checks, daily. They report only and never repair: a run that finds
// problems is logged as an error; run the command manually to see the details.
foreach (['wallet:verify', 'purchases:verify', 'commissions:verify'] as $check) {
    Schedule::command($check)->daily()
        ->onFailure(fn () => Log::error('Scheduled integrity check found problems', ['command' => $check]));
}
