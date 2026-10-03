<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delete expired Sanctum API tokens (needs the scheduler cron at deployment).
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Payments: recheck pending payments with their gateway, and clear old webhook payloads.
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('payments:prune-webhooks')->daily();
