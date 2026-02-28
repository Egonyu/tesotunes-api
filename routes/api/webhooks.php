<?php

use App\Http\Controllers\Api\Webhooks\ZengaPayWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Public webhook endpoints (no auth required).
| ZengaPay sends payment status callbacks to these endpoints.
|
| Note: Additional webhook routes are defined in routes/api.php
|
*/

// ZengaPay payment callback — signature verified in ZengaPayService
Route::post('/webhooks/zengapay', ZengaPayWebhookController::class)
    ->middleware('webhook.rate_limit')
    ->name('api.webhooks.zengapay');
