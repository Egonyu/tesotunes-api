<?php

use Illuminate\Support\Facades\Route;

// Ad serving + tracking (no auth required — free users may not be logged in)
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/ads', [\App\Http\Controllers\Api\AdServingController::class, 'serve']);
});

Route::middleware('throttle:ad-tracking')->group(function () {
    Route::post('/ads/impression', [\App\Http\Controllers\Api\AdTrackingController::class, 'recordImpression']);
    Route::post('/ads/click', [\App\Http\Controllers\Api\AdTrackingController::class, 'recordClick']);
});

// Theme preference (works for both guests and authenticated users)
Route::post('/theme', [\App\Http\Controllers\ThemeController::class, 'update'])
    ->middleware('throttle:theme')
    ->name('api.theme.update');
Route::get('/theme', [\App\Http\Controllers\ThemeController::class, 'get'])->name('api.theme.get');
Route::get('/settings/public', [\App\Http\Controllers\Api\Admin\SettingsRegistryController::class, 'publicIndex'])->name('api.settings.public');

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
