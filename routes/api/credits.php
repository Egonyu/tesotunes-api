<?php

use Illuminate\Support\Facades\Route;

/**
 * The credits guide is public: someone deciding whether to join should be able
 * to read what the platform pays. It carries the reader's own allowance when a
 * token happens to be present, so it needs no auth of its own.
 */
Route::get('/credits/guide', [\App\Http\Controllers\Api\CreditGuideController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('api.credits.guide');

// User Credits Routes
Route::prefix('credits')->middleware('auth:sanctum')->name('api.credits.')->group(function () {
    Route::get('/balance', [\App\Http\Controllers\Api\User\CreditController::class, 'balance'])->name('balance');
    Route::get('/dashboard', [\App\Http\Controllers\Api\User\CreditController::class, 'dashboard'])->name('dashboard');
    Route::get('/transactions', [\App\Http\Controllers\Api\User\CreditController::class, 'transactions'])->name('transactions');
    Route::post('/purchase', [\App\Http\Controllers\Api\User\CreditController::class, 'purchase'])->name('purchase');
    Route::post('/exchange', [\App\Http\Controllers\Api\User\CreditController::class, 'exchange'])->name('exchange');
    Route::post('/claim-daily-bonus', [\App\Http\Controllers\Api\User\CreditController::class, 'claimDailyBonus'])->name('claim-daily-bonus');
    Route::post('/transfer', [\App\Http\Controllers\Api\User\CreditController::class, 'transfer'])->middleware('wallet.pin')->name('transfer');
});
