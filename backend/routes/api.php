<?php

use Illuminate\Support\Facades\Route;

// Mounted by Laravel under /api. Each API version lives in its own file.
Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('routes/api/v1.php'));
