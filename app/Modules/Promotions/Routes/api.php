<?php

use App\Modules\Promotions\Http\Controllers\Api\ActivityHubController;
use App\Modules\Promotions\Http\Controllers\Api\AdminPromoterController;
use App\Modules\Promotions\Http\Controllers\Api\OpportunityController;
use App\Modules\Promotions\Http\Controllers\Api\PromoterOnboardingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Promotions V2 — Opportunity feed, promoter onboarding, activity hub
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

// --- Opportunity feed (artist posts briefs, influencers apply) ---
Route::prefix('opportunities')->name('opportunities.')->group(function () {
    Route::get('/', [OpportunityController::class, 'index'])->name('index');
    Route::get('/{uuid}', [OpportunityController::class, 'show'])->name('show');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/', [OpportunityController::class, 'store'])->name('store');
        Route::put('/{uuid}', [OpportunityController::class, 'update'])->name('update');
        Route::delete('/{uuid}', [OpportunityController::class, 'destroy'])->name('destroy');
        Route::post('/{uuid}/close', [OpportunityController::class, 'close'])->name('close');

        // Applications
        Route::post('/{uuid}/apply', [OpportunityController::class, 'apply'])->name('apply');
        Route::get('/{uuid}/applications', [OpportunityController::class, 'applications'])->name('applications');
        Route::post('/{uuid}/applications/{applicationId}/award', [OpportunityController::class, 'award'])->name('applications.award');
        Route::post('/{uuid}/applications/{applicationId}/shortlist', [OpportunityController::class, 'shortlist'])->name('applications.shortlist');
        Route::delete('/{uuid}/applications/{applicationId}', [OpportunityController::class, 'withdrawApplication'])->name('applications.withdraw');

        // My posted opportunities
        Route::get('/my/posted', [OpportunityController::class, 'myPosted'])->name('my.posted');
        // My applications as a promoter
        Route::get('/my/applications', [OpportunityController::class, 'myApplications'])->name('my.applications');
    });
});

// --- Admin V2: Promoter management + Opportunity oversight ---
Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->group(function () {
    // Promoter profiles
    Route::get('/admin/promoters', [AdminPromoterController::class, 'index'])->name('admin.promoters.index');
    Route::post('/admin/promoters/{id}/verify', [AdminPromoterController::class, 'verify'])->name('admin.promoters.verify');
    Route::post('/admin/promoters/{id}/unverify', [AdminPromoterController::class, 'unverify'])->name('admin.promoters.unverify');
    Route::put('/admin/promoters/{id}/tier', [AdminPromoterController::class, 'setTier'])->name('admin.promoters.tier');

    // Opportunities
    Route::get('/admin/opportunities', [AdminPromoterController::class, 'indexOpportunities'])->name('admin.opportunities.index');
    Route::post('/admin/opportunities/{uuid}/close', [AdminPromoterController::class, 'forceClose'])->name('admin.opportunities.close');
    Route::get('/admin/opportunities/{uuid}/applications', [AdminPromoterController::class, 'opportunityApplications'])->name('admin.opportunities.applications');
});

// --- Universal Activity Hub (replaces /promotions/purchases + /artist/promotions) ---
Route::middleware('auth:sanctum')->prefix('activity-hub')->name('activity-hub.')->group(function () {
    Route::get('/summary', [ActivityHubController::class, 'summary'])->name('summary');
    Route::get('/wallet', [ActivityHubController::class, 'wallet'])->name('wallet');
    Route::get('/orders', [ActivityHubController::class, 'orders'])->name('orders');
    Route::get('/opportunities', [ActivityHubController::class, 'opportunities'])->name('opportunities');
    Route::get('/applications', [ActivityHubController::class, 'applications'])->name('applications');
    Route::get('/earnings', [ActivityHubController::class, 'earnings'])->name('earnings');
});
