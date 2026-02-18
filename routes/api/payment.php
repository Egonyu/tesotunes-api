<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payment API Routes
|--------------------------------------------------------------------------
|
| ZengaPay payment endpoints for subscriptions, one-time payments,
| refunds, payouts, and transaction status checks.
|
| Note: Core payment routes are also defined in routes/api.php
| under the /payments and /subscriptions prefixes.
|
*/

Route::middleware('auth:sanctum')->prefix('payments')->name('api.payments.')->group(function () {
    // Check ZengaPay transaction status
    Route::get(
        '/status/{transactionId}',
        [\App\Http\Controllers\Api\PaymentController::class, 'checkStatus']
    )->name('status');

    // Get ZengaPay account balance (admin only)
    Route::get(
        '/zengapay/balance',
        [\App\Http\Controllers\Api\PaymentController::class, 'zengapayBalance']
    )->name('zengapay.balance')->middleware('role:admin');

    // Get user's payment history
    Route::get(
        '/history',
        [\App\Http\Controllers\Api\PaymentController::class, 'history']
    )->name('history');
});
