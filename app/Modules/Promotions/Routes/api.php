<?php

use App\Modules\Promotions\Http\Controllers\Api\ActivityHubController;
use App\Modules\Promotions\Http\Controllers\Api\AdminPromoterController;
use App\Modules\Promotions\Http\Controllers\Api\PromoterOnboardingController;
use App\Modules\Promotions\Http\Controllers\Api\PromotionRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Promotions V2 — Promotion request feed, promoter onboarding, activity hub
|--------------------------------------------------------------------------
| Public browse is unauthenticated.
| All write actions require auth:sanctum.
| No artist role restriction — any user can become a promoter.
*/

// --- Promoter onboarding (no role restriction) ---
Route::prefix('promoters')->name('promoters.')->group(function () {
    Route::get('/discover', [PromoterOnboardingController::class, 'discover'])->name('discover');
    Route::get('/{slug}', [PromoterOnboardingController::class, 'show'])->name('show');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/onboard', [PromoterOnboardingController::class, 'onboard'])->name('onboard');
        Route::get('/me/profile', [PromoterOnboardingController::class, 'myProfile'])->name('me.profile');
        Route::put('/me/profile', [PromoterOnboardingController::class, 'updateProfile'])->name('me.profile.update');
    });
});

// --- Promotion request feed (artist posts briefs, influencers apply) ---
Route::prefix('promotion-requests')->name('promotion-requests.')->group(function () {
    Route::get('/', [PromotionRequestController::class, 'index'])->name('index');
    Route::get('/{uuid}', [PromotionRequestController::class, 'show'])->name('show');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/', [PromotionRequestController::class, 'store'])->name('store');
        Route::put('/{uuid}', [PromotionRequestController::class, 'update'])->name('update');
        Route::delete('/{uuid}', [PromotionRequestController::class, 'destroy'])->name('destroy');
        Route::post('/{uuid}/close', [PromotionRequestController::class, 'close'])->name('close');

        // Applications
        Route::post('/{uuid}/apply', [PromotionRequestController::class, 'apply'])->name('apply');
        Route::get('/{uuid}/applications', [PromotionRequestController::class, 'applications'])->name('applications');
        Route::post('/{uuid}/applications/{applicationId}/award', [PromotionRequestController::class, 'award'])->name('applications.award');
        Route::post('/{uuid}/applications/{applicationId}/shortlist', [PromotionRequestController::class, 'shortlist'])->name('applications.shortlist');
        Route::delete('/{uuid}/applications/{applicationId}', [PromotionRequestController::class, 'withdrawApplication'])->name('applications.withdraw');

        // Requests I have posted
        Route::get('/my/posted', [PromotionRequestController::class, 'myPosted'])->name('my.posted');
        // My applications as a promoter
        Route::get('/my/applications', [PromotionRequestController::class, 'myApplications'])->name('my.applications');
    });
});

// --- Admin V2: Promoter management + promotion request oversight ---
Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->group(function () {
    // Promoter profiles
    Route::get('/admin/promoters', [AdminPromoterController::class, 'index'])->name('admin.promoters.index');
    Route::post('/admin/promoters/{id}/verify', [AdminPromoterController::class, 'verify'])->name('admin.promoters.verify');
    Route::post('/admin/promoters/{id}/unverify', [AdminPromoterController::class, 'unverify'])->name('admin.promoters.unverify');
    Route::put('/admin/promoters/{id}/tier', [AdminPromoterController::class, 'setTier'])->name('admin.promoters.tier');

    // Promotion requests
    Route::get('/admin/promotion-requests', [AdminPromoterController::class, 'indexPromotionRequests'])->name('admin.promotion-requests.index');
    Route::post('/admin/promotion-requests/{uuid}/close', [AdminPromoterController::class, 'forceClose'])->name('admin.promotion-requests.close');
    Route::get('/admin/promotion-requests/{uuid}/applications', [AdminPromoterController::class, 'promotionRequestApplications'])->name('admin.promotion-requests.applications');
});

// --- Universal Activity Hub (replaces /promotions/purchases + /artist/promotions) ---
Route::middleware('auth:sanctum')->prefix('activity-hub')->name('activity-hub.')->group(function () {
    Route::get('/summary', [ActivityHubController::class, 'summary'])->name('summary');
    Route::get('/wallet', [ActivityHubController::class, 'wallet'])->name('wallet');
    Route::get('/orders', [ActivityHubController::class, 'orders'])->name('orders');
    Route::get('/promotion-requests', [ActivityHubController::class, 'promotionRequests'])->name('promotion-requests');
    Route::get('/applications', [ActivityHubController::class, 'applications'])->name('applications');
    Route::get('/earnings', [ActivityHubController::class, 'earnings'])->name('earnings');
});
