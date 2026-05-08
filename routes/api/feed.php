<?php

use Illuminate\Support\Facades\Route;

Route::prefix('feed')->name('api.feed.')->group(function () {
    // Public feed endpoints (guests can browse)
    Route::get('/', [\App\Http\Controllers\Api\FeedController::class, 'index'])->name('index');
    Route::get('/for-you', [\App\Http\Controllers\Api\FeedController::class, 'forYou'])->name('for-you');
    Route::get('/discover', [\App\Http\Controllers\Api\FeedController::class, 'discover'])->name('discover');
    Route::get('/module/{module}', [\App\Http\Controllers\Api\FeedController::class, 'module'])->name('module');
    Route::get('/tabs', [\App\Http\Controllers\Api\FeedController::class, 'tabs'])->name('tabs');
    Route::get('/trending', [\App\Http\Controllers\Api\FeedController::class, 'trending'])->name('trending');

    // Authenticated feed endpoints (MUST be before /{uuid} to avoid route conflicts)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/following', [\App\Http\Controllers\Api\FeedController::class, 'following'])->name('following');
        Route::get('/saved', [\App\Http\Controllers\Api\FeedController::class, 'saved'])->name('saved');

        Route::post('/{uuid}/not-interested', [\App\Http\Controllers\Api\FeedController::class, 'notInterested'])->name('not-interested');
        Route::post('/{uuid}/hide', [\App\Http\Controllers\Api\FeedController::class, 'hide'])->name('hide');
        Route::post('/{uuid}/save', [\App\Http\Controllers\Api\FeedController::class, 'save'])->name('save');
        Route::delete('/{uuid}/save', [\App\Http\Controllers\Api\FeedController::class, 'unsave'])->name('unsave');

        Route::post('/{uuid}/click', [\App\Http\Controllers\Api\FeedController::class, 'trackClick'])->name('track-click');
        Route::post('/{uuid}/engage', [\App\Http\Controllers\Api\FeedController::class, 'trackEngagement'])->name('track-engagement');

        Route::post('/refresh', [\App\Http\Controllers\Api\FeedController::class, 'refresh'])->name('refresh');
        Route::get('/preferences', [\App\Http\Controllers\Api\FeedController::class, 'preferences'])->name('preferences');
        Route::put('/preferences', [\App\Http\Controllers\Api\FeedController::class, 'updatePreferences'])->name('update-preferences');
    });

    // Single item view (MUST be after named routes like /following, /saved)
    Route::get('/{uuid}', [\App\Http\Controllers\Api\FeedController::class, 'show'])->name('show');
});
