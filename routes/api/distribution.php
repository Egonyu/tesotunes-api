<?php

use Illuminate\Support\Facades\Route;

// ISRC Generation Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/songs/{song}/generate-isrc', [\App\Http\Controllers\Api\ISRCController::class, 'generateForSong'])->name('api.isrc.generate');
    Route::post('/albums/{album}/generate-isrc', [\App\Http\Controllers\Api\ISRCController::class, 'generateForAlbum'])->name('api.isrc.generate-album');
    Route::post('/albums/{album}/generate-isrc-batch', [\App\Http\Controllers\Api\ISRCController::class, 'generateBatchForAlbum'])->name('api.isrc.generate-batch');
    Route::post('/isrc/{isrc}/register', [\App\Http\Controllers\Api\ISRCController::class, 'register'])->name('api.isrc.register');
    Route::post('/isrc/{isrc}/clearance', [\App\Http\Controllers\Api\ISRCController::class, 'clearance'])->name('api.isrc.clearance');
    Route::post('/isrc/{isrc}/clear-for-distribution', [\App\Http\Controllers\Api\ISRCController::class, 'clearance'])->name('api.isrc.clear-distribution');
    Route::post('/isrc/bulk', [\App\Http\Controllers\Api\ISRCController::class, 'bulkOperation'])->name('api.isrc.bulk');
    Route::post('/isrc/bulk-register', [\App\Http\Controllers\Api\ISRCController::class, 'bulkRegister'])->name('api.isrc.bulk-register');
    Route::post('/isrc/bulk-clear-distribution', [\App\Http\Controllers\Api\ISRCController::class, 'bulkClearDistribution'])->name('api.isrc.bulk-clear-distribution');
    Route::get('/isrc', [\App\Http\Controllers\Api\ISRCController::class, 'index'])->name('api.isrc.index');
    Route::get('/isrc/search', [\App\Http\Controllers\Api\ISRCController::class, 'search'])->name('api.isrc.search');
    Route::get('/isrc/export', [\App\Http\Controllers\Api\ISRCController::class, 'export'])->name('api.isrc.export');
    Route::post('/isrc/check-duplicate', [\App\Http\Controllers\Api\ISRCController::class, 'checkDuplicate'])->name('api.isrc.check-duplicate');
    Route::get('/isrc/analytics', [\App\Http\Controllers\Api\ISRCController::class, 'analytics'])->name('api.isrc.analytics');
});

// Song Distribution Routes
Route::middleware('auth:sanctum')->prefix('songs')->name('api.songs.')->group(function () {
    Route::post('/{song}/distribute', [\App\Http\Controllers\DistributionController::class, 'submitSongDistribution'])->name('distribute');
    Route::get('/{song}/distributions', [\App\Http\Controllers\DistributionController::class, 'getSongDistributions'])->name('distributions');
    Route::post('/{song}/distributions/{distribution}/remove', [\App\Http\Controllers\DistributionController::class, 'requestRemoval'])->name('distribution.remove');
});

Route::middleware('auth:sanctum')->prefix('albums')->name('api.albums.')->group(function () {
    Route::post('/{album}/distribute', [\App\Http\Controllers\DistributionController::class, 'distributeAlbum'])->name('distribute');
});

Route::middleware('auth:sanctum')->prefix('distributions')->name('api.distributions.')->group(function () {
    Route::post('/bulk-submit', [\App\Http\Controllers\DistributionController::class, 'bulkSubmit'])->name('bulk-submit');
    Route::get('/{distribution}/status', [\App\Http\Controllers\DistributionController::class, 'getStatus'])->name('status');
    Route::post('/{distribution}/remove', [\App\Http\Controllers\DistributionController::class, 'requestRemoval'])->name('remove');
    Route::post('/{distribution}/retry', [\App\Http\Controllers\DistributionController::class, 'retryDistribution'])->name('retry');
    Route::get('/{distribution}/royalty-report', [\App\Http\Controllers\DistributionController::class, 'getRoyaltyReport'])->name('royalty-report');
});

Route::middleware('auth:sanctum')->prefix('artist')->name('api.artist.')->group(function () {
    Route::get('/distribution-analytics', [\App\Http\Controllers\DistributionController::class, 'getAnalytics'])->name('distribution-analytics');

    // Artist Application Routes
    Route::get('/application-status', [\App\Http\Controllers\Api\ArtistApplicationApiController::class, 'status'])->name('application-status');
    Route::post('/apply', [\App\Http\Controllers\Api\ArtistApplicationApiController::class, 'store'])->name('apply');
});

// Distribution platform webhooks (public, rate limited)
Route::prefix('webhooks/distribution')->middleware('webhook.rate_limit')->name('api.webhooks.distribution.')->group(function () {
    Route::post('/{platform}', [\App\Http\Controllers\DistributionWebhookController::class, 'handle'])->name('handle');
});

// Admin Distribution Performance
Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])
    ->prefix('admin/distribution-performance')
    ->name('api.admin.distribution.')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Admin\DistributionPerformanceController::class, 'performance'])->name('performance');
    });
