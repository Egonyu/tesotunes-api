<?php

use Illuminate\Support\Facades\Route;

// Ad tracking endpoints (no auth required for impressions)
Route::middleware('throttle:ad-tracking')->group(function () {
    Route::post('/ads/impression', [\App\Http\Controllers\Api\AdTrackingController::class, 'recordImpression']);
    Route::post('/ads/click', [\App\Http\Controllers\Api\AdTrackingController::class, 'recordClick']);
});

// Theme preference (works for both guests and authenticated users)
Route::post('/theme', [\App\Http\Controllers\ThemeController::class, 'update'])
    ->middleware('throttle:theme')
    ->name('api.theme.update');
Route::get('/theme', [\App\Http\Controllers\ThemeController::class, 'get'])->name('api.theme.get');
Route::get('/platform-settings', [\App\Http\Controllers\Api\Admin\SettingsController::class, 'publicIndex'])->name('api.platform-settings.public');

// Genres API endpoint for artist registration
Route::get('/genres', [\App\Http\Controllers\Api\GenreController::class, 'index']);

// Slideshow API endpoints
Route::prefix('slideshow')->name('api.slideshow.')->group(function () {
    Route::get('/{section}', [\App\Http\Controllers\Api\SlideshowController::class, 'index'])
        ->where('section', 'home|discover|radio|community|trending|channels|all')
        ->name('section');
    Route::get('/genre/{slug}', [\App\Http\Controllers\Api\SlideshowController::class, 'byGenre'])->name('genre');
    Route::get('/mood/{slug}', [\App\Http\Controllers\Api\SlideshowController::class, 'byMood'])->name('mood');
});
