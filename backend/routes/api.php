<?php

use App\Http\Controllers\Webhooks\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

// Mounted by Laravel under /api. Each API version lives in its own file.
Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('routes/api/v1.php'));

// Payment gateway webhooks (server to server, not versioned): stateless, no
// session or CSRF. Authenticated by the gateway adapter; never trusted for money.
Route::post('webhooks/payments/{gatewayCode}', PaymentWebhookController::class)
    ->where('gatewayCode', '[a-z0-9][a-z0-9-]{0,99}')
    ->middleware('throttle:120,1')
    ->name('webhooks.payments');
