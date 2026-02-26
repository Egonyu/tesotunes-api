<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Artist\LoyaltyCardController;
use App\Http\Controllers\Api\Artist\LoyaltyRewardController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\LoyaltyPointsController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\Admin\LoyaltyAdminController;

/*
|--------------------------------------------------------------------------
| Loyalty API Routes
|--------------------------------------------------------------------------
|
| Artist fan club / loyalty card system with tiered memberships,
| rewards, points, and cross-module integration.
|
*/

// ──────────────────────────────────────────────────────────
// Public (no auth required)
// ──────────────────────────────────────────────────────────

Route::prefix('loyalty-cards')->name('api.loyalty-cards.')->group(function () {
    Route::get('/', [LoyaltyController::class, 'index'])->name('index');
    Route::get('/{loyaltyCard}', [LoyaltyController::class, 'show'])->name('show');
});

// ──────────────────────────────────────────────────────────
// Authenticated Fan routes
// ──────────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {

    // Join a loyalty card
    Route::post('/loyalty-cards/{loyaltyCard}/join', [LoyaltyController::class, 'join'])
        ->name('api.loyalty-cards.join');

    // Available rewards for a card
    Route::get('/loyalty-cards/{loyaltyCard}/rewards', [LoyaltyController::class, 'availableRewards'])
        ->name('api.loyalty-cards.rewards');

    // Redeem a reward
    Route::post('/loyalty-cards/{loyaltyCard}/rewards/{reward}/redeem', [LoyaltyController::class, 'redeemReward'])
        ->name('api.loyalty-cards.rewards.redeem');

    // My memberships
    Route::prefix('my/memberships')->name('api.my.memberships.')->group(function () {
        Route::get('/', [MembershipController::class, 'index'])->name('index');
        Route::get('/{membership}', [MembershipController::class, 'show'])->name('show');
        Route::put('/{membership}', [MembershipController::class, 'update'])->name('update');
        Route::post('/{membership}/cancel', [MembershipController::class, 'cancel'])->name('cancel');
        Route::post('/{membership}/renew', [MembershipController::class, 'renew'])->name('renew');
    });

    // My loyalty points
    Route::prefix('my/loyalty-points')->name('api.my.loyalty-points.')->group(function () {
        Route::get('/', [LoyaltyPointsController::class, 'show'])->name('show');
        Route::get('/transactions', [LoyaltyPointsController::class, 'transactions'])->name('transactions');
        Route::post('/convert', [LoyaltyPointsController::class, 'convert'])->name('convert');
    });
});

// ──────────────────────────────────────────────────────────
// Artist routes (auth + artist role)
// ──────────────────────────────────────────────────────────

Route::middleware(['auth:sanctum'])->prefix('artist/loyalty-cards')->name('api.artist.loyalty-cards.')->group(function () {

    Route::get('/', [LoyaltyCardController::class, 'index'])->name('index');
    Route::post('/', [LoyaltyCardController::class, 'store'])->name('store');
    Route::get('/{loyaltyCard}', [LoyaltyCardController::class, 'show'])->name('show');
    Route::put('/{loyaltyCard}', [LoyaltyCardController::class, 'update'])->name('update');
    Route::delete('/{loyaltyCard}', [LoyaltyCardController::class, 'destroy'])->name('destroy');

    // Publish / unpublish
    Route::post('/{loyaltyCard}/publish', [LoyaltyCardController::class, 'publish'])->name('publish');

    // Members management
    Route::get('/{loyaltyCard}/members', [LoyaltyCardController::class, 'members'])->name('members');

    // Analytics
    Route::get('/{loyaltyCard}/analytics', [LoyaltyCardController::class, 'analytics'])->name('analytics');

    // Rewards nested under a card
    Route::prefix('/{loyaltyCard}/rewards')->name('rewards.')->group(function () {
        Route::get('/', [LoyaltyRewardController::class, 'index'])->name('index');
        Route::post('/', [LoyaltyRewardController::class, 'store'])->name('store');
        Route::put('/{reward}', [LoyaltyRewardController::class, 'update'])->name('update');
        Route::delete('/{reward}', [LoyaltyRewardController::class, 'destroy'])->name('destroy');
    });
});

// ──────────────────────────────────────────────────────────
// Admin routes
// ──────────────────────────────────────────────────────────

Route::middleware(['auth:sanctum'])->prefix('admin/loyalty')->name('api.admin.loyalty.')->group(function () {
    Route::get('/cards', [LoyaltyAdminController::class, 'cards'])->name('cards');
    Route::get('/cards/{loyaltyCard}', [LoyaltyAdminController::class, 'showCard'])->name('cards.show');
    Route::post('/cards/{loyaltyCard}/approve', [LoyaltyAdminController::class, 'approve'])->name('cards.approve');
    Route::post('/cards/{loyaltyCard}/suspend', [LoyaltyAdminController::class, 'suspend'])->name('cards.suspend');
    Route::get('/analytics', [LoyaltyAdminController::class, 'analytics'])->name('analytics');
});
