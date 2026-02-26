<?php

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
Route::post('/webhooks/zengapay', function (\Illuminate\Http\Request $request) {
    $service = app(\App\Services\Payment\ZengaPayService::class);

    // Verify webhook signature if configured
    $signature = $request->header('X-ZengaPay-Signature', '');
    if (! $service->verifyWebhookSignature($request->getContent(), $signature)) {
        \Illuminate\Support\Facades\Log::warning('ZengaPay webhook: invalid signature');

        return response()->json(['message' => 'Invalid signature'], 403);
    }

    $result = $service->handleWebhook($request->all());

    $statusCode = ($result['success'] ?? false) ? 200 : 404;

    return response()->json($result, $statusCode);
})->middleware('webhook.rate_limit')->name('api.webhooks.zengapay');
