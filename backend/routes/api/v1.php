<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

// Infrastructure only. Business endpoints arrive in later phases.
// Use controllers, never closures, so `php artisan route:cache` works on cPanel.
Route::get('health', HealthController::class)->name('health');
