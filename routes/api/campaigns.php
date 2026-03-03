<?php

use App\Http\Controllers\Api\CampaignController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Ojokotau / Crowdfunding Campaign Routes
|--------------------------------------------------------------------------
|
| Public-facing routes for browsing campaigns, making pledges,
| and managing own campaigns. Admin campaign management is
| in routes/api.php under the admin prefix.
|
*/

// Public campaign browsing (no auth required)
Route::prefix('campaigns')->name('api.campaigns.')->group(function () {
    Route::get('/', [CampaignController::class, 'index'])->name('index');
    Route::get('/featured', [CampaignController::class, 'featured'])->name('featured');
    Route::get('/categories', [CampaignController::class, 'categories'])->name('categories');

    // Authenticated campaign actions (MUST be before /{slug} to avoid route conflict)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/my', [CampaignController::class, 'myCampaigns'])->name('my');
        Route::post('/', [CampaignController::class, 'store'])->name('store');
        Route::put('/{slug}', [CampaignController::class, 'update'])->name('update');
        Route::post('/{slug}/pledge', [CampaignController::class, 'pledge'])->name('pledge');
        Route::post('/{slug}/updates', [CampaignController::class, 'addUpdate'])->name('add-update');
        Route::post('/{slug}/share', [CampaignController::class, 'share'])->name('share');
    });

    // Single campaign view (MUST be after named routes like /my, /featured, /categories)
    Route::get('/{slug}', [CampaignController::class, 'show'])->name('show');
    Route::get('/{slug}/pledges', [CampaignController::class, 'pledges'])->name('pledges');
    Route::get('/{slug}/updates', [CampaignController::class, 'updates'])->name('updates');
});
