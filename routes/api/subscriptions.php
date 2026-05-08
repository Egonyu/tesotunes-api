<?php

use Illuminate\Support\Facades\Route;

// Payout API Routes
Route::middleware('auth:sanctum')->prefix('payouts')->name('api.payouts.')->group(function () {
    Route::post('/request', [\App\Http\Controllers\Api\PayoutController::class, 'requestPayout'])->name('request');
});

// Subscription API Routes — public plan list + authenticated subscription management
Route::get('/subscription-plans', [\App\Http\Controllers\Api\SubscriptionController::class, 'plans'])->name('api.subscription-plans');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/subscription', [\App\Http\Controllers\Api\SubscriptionController::class, 'current'])->name('api.user.subscription');
    Route::get('/user/subscription/history', [\App\Http\Controllers\Api\SubscriptionController::class, 'history'])->name('api.user.subscription.history');
    Route::post('/subscriptions/subscribe', [\App\Http\Controllers\Api\SubscriptionController::class, 'subscribe'])->name('api.subscriptions.subscribe');
    Route::post('/subscriptions/change-plan', [\App\Http\Controllers\Api\SubscriptionController::class, 'changePlan'])->name('api.subscriptions.change-plan');
    Route::post('/subscriptions/toggle-auto-renew', [\App\Http\Controllers\Api\SubscriptionController::class, 'toggleAutoRenew'])->name('api.subscriptions.toggle-auto-renew');
});

Route::middleware('auth:sanctum')->prefix('subscriptions')->name('api.subscriptions.')->group(function () {
    Route::post('/{subscription}/cancel', [\App\Http\Controllers\Api\SubscriptionController::class, 'cancel'])->name('cancel');
    Route::post('/{subscription}/extend', [\App\Http\Controllers\Api\SubscriptionController::class, 'extend'])->name('extend')->middleware('role:admin,super_admin');
});

// Core payment routes (subscription + refund + artist-payout)
Route::middleware('auth:sanctum')->prefix('payments')->name('api.payments.subscription.')->group(function () {
    Route::post('/subscription', [\App\Http\Controllers\Api\PaymentController::class, 'processSubscription'])->name('process');
    Route::post('/{payment}/refund', [\App\Http\Controllers\Api\PaymentController::class, 'refund'])->name('refund');
    Route::post('/artist-payout', [\App\Http\Controllers\Api\PaymentController::class, 'artistPayout'])->name('artist-payout')->middleware('role:admin,super_admin');
});
