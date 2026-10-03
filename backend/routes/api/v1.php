<?php

use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

// Infrastructure only. Business endpoints arrive in later phases.
// Use controllers, never closures, so `php artisan route:cache` works on cPanel.
Route::get('health', HealthController::class)->name('health');

// Authentication foundation (Sanctum bearer tokens). No business endpoints yet.
Route::post('auth/token', [TokenController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('auth.token.store');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::delete('auth/token', [TokenController::class, 'destroy'])->name('auth.token.destroy');
    Route::get('user', CurrentUserController::class)->name('user');
});
