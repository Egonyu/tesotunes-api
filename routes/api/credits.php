<?php

use Illuminate\Support\Facades\Route;

// User Credits Routes
Route::prefix('credits')->middleware('auth:sanctum')->name('api.credits.')->group(function () {
    Route::get('/balance', [\App\Http\Controllers\Api\User\CreditController::class, 'balance'])->name('balance');
    Route::get('/dashboard', [\App\Http\Controllers\Api\User\CreditController::class, 'dashboard'])->name('dashboard');
    Route::get('/transactions', [\App\Http\Controllers\Api\User\CreditController::class, 'transactions'])->name('transactions');
    Route::post('/purchase', [\App\Http\Controllers\Api\User\CreditController::class, 'purchase'])->name('purchase');
    Route::post('/exchange', [\App\Http\Controllers\Api\User\CreditController::class, 'exchange'])->name('exchange');
    Route::post('/claim-daily-bonus', [\App\Http\Controllers\Api\User\CreditController::class, 'claimDailyBonus'])->name('claim-daily-bonus');
    Route::post('/transfer', [\App\Http\Controllers\Api\User\CreditController::class, 'transfer'])->name('transfer');
    Route::get('/promotions', [\App\Http\Controllers\Api\User\CreditController::class, 'promotions'])->name('promotions');
    Route::post('/promotions/{promotion}/participate', [\App\Http\Controllers\Api\User\CreditController::class, 'participateInPromotion'])->name('promotions.participate');
});
