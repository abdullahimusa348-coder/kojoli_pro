<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delete expired Sanctum API tokens (needs the scheduler cron at deployment).
Schedule::command('sanctum:prune-expired --hours=24')->daily();
