<?php

use Illuminate\Support\Facades\Route;

// Track streaming — rate-limited, auth optional (quality gated by subscription)
Route::middleware(['api.rate_limit:30:1'])->prefix('tracks')->name('api.tracks.')->group(function () {
    Route::get('/{track}/stream-url', [\App\Http\Controllers\Api\MusicController::class, 'getStreamUrl'])
        ->name('stream-url');
    Route::get('/{track}/download-url', [\App\Http\Controllers\Api\MusicController::class, 'getDownloadUrl'])
        ->middleware('auth:sanctum')
        ->name('download-url');
});

// Player API endpoints — playback controls, queue management
Route::middleware('auth:sanctum')->prefix('player')->name('api.player.')->group(function () {
    Route::post('/update-now-playing', [\App\Http\Controllers\Api\PlayerController::class, 'updateNowPlaying'])->name('now-playing');
    Route::post('/record-play', [\App\Http\Controllers\Api\PlayerController::class, 'recordPlay'])->name('record-play');
    Route::post('/save-position', [\App\Http\Controllers\Api\PlayerController::class, 'savePosition'])->name('save-position');
    Route::get('/resume-position/{songId}', [\App\Http\Controllers\Api\PlayerController::class, 'getResumePosition'])->name('resume-position');

    // Extended player controls
    Route::get('/status', [\App\Http\Controllers\Api\Player\PlayerController::class, 'getStatus'])->name('status');
    Route::post('/previous', [\App\Http\Controllers\Api\Player\PlayerController::class, 'previous'])->name('previous');
    Route::post('/next', [\App\Http\Controllers\Api\Player\PlayerController::class, 'next'])->name('next');
    Route::post('/seek', [\App\Http\Controllers\Api\Player\PlayerController::class, 'seek'])->name('seek');

    // Queue management
    Route::get('/queue', [\App\Http\Controllers\Api\Player\QueueController::class, 'getQueue'])->name('queue.index');
    Route::post('/queue', [\App\Http\Controllers\Api\Player\QueueController::class, 'addToQueue'])->name('queue.add');
    Route::delete('/queue', [\App\Http\Controllers\Api\Player\QueueController::class, 'clearQueue'])->name('queue.clear');
    Route::post('/queue/shuffle', [\App\Http\Controllers\Api\Player\QueueController::class, 'shuffleQueue'])->name('queue.shuffle');
    Route::put('/queue/reorder', [\App\Http\Controllers\Api\Player\QueueController::class, 'reorderQueue'])->name('queue.reorder');
    Route::delete('/queue/{queueItem}', [\App\Http\Controllers\Api\Player\QueueController::class, 'removeFromQueue'])->name('queue.remove');
});
