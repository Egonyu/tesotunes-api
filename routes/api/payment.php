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
    // Initiate mobile money deposit (wallet topup)
    Route::post(
        '/mobile-money/initiate',
        [\App\Http\Controllers\Api\PaymentController::class, 'initiateMobileMoneyDeposit']
    )->name('mobile-money.initiate');

    // Check payment status by reference
    Route::get(
        '/mobile-money/status/{reference}',
        [\App\Http\Controllers\Api\PaymentController::class, 'mobileMoneyStatus']
    )->name('mobile-money.status');

    // Get wallet info
    Route::get(
        '/wallet',
        [\App\Http\Controllers\Api\PaymentController::class, 'wallet']
    )->name('wallet');

    // Wallet transactions
    Route::get(
        '/wallet/transactions',
        [\App\Http\Controllers\Api\PaymentController::class, 'walletTransactions']
    )->name('wallet.transactions');

    // Wallet withdraw
    Route::post(
        '/wallet/withdraw',
        [\App\Http\Controllers\Api\PaymentController::class, 'withdraw']
    )->name('wallet.withdraw');

    // Check ZengaPay transaction status
    Route::get(
        '/status/{transactionId}',
        [\App\Http\Controllers\Api\PaymentController::class, 'checkStatus']
    )->name('status');

    // Get ZengaPay account balance (admin only)
    Route::get(
        '/zengapay/balance',
        [\App\Http\Controllers\Api\PaymentController::class, 'zengapayBalance']
    )->name('zengapay.balance')->middleware('role:admin,super_admin');

    // Get user's payment history
    Route::get(
        '/history',
        [\App\Http\Controllers\Api\PaymentController::class, 'history']
    )->name('history');
});
