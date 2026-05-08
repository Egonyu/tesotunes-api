<?php

use Illuminate\Support\Facades\Route;

// Podcast API Routes
Route::prefix('podcasts')->name('api.podcast.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'index']);
    Route::get('/{podcast:uuid}', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'show']);
    Route::get('/{podcast:uuid}/episodes', [\App\Http\Controllers\Api\Podcast\EpisodeApiController::class, 'index']);
    Route::get('/{podcast:uuid}/rss', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'rssFeed'])->name('rss');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/{podcast:uuid}/subscribe', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'subscribe']);
        Route::delete('/{podcast:uuid}/unsubscribe', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'unsubscribe']);
    });
});

// Podcast episodes
Route::get('/episodes/{episode:uuid}', [\App\Http\Controllers\Api\Podcast\EpisodeApiController::class, 'show']);

// Podcast search & discovery
Route::get('/podcasts-search', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'search']);
Route::get('/podcasts-trending', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'trending']);
Route::get('/podcast-categories', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'categories']);

// Podcast player & analytics (authenticated)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/episodes/{episode:uuid}/play', [\App\Http\Controllers\Api\Podcast\PlayerApiController::class, 'play']);
    Route::post('/episodes/{episode:uuid}/progress', [\App\Http\Controllers\Api\Podcast\PlayerApiController::class, 'updateProgress']);
    Route::post('/episodes/{episode:uuid}/complete', [\App\Http\Controllers\Api\Podcast\PlayerApiController::class, 'markComplete']);

    Route::post('/episodes/{episode:uuid}/track-listen', [\App\Http\Controllers\Api\Podcast\AnalyticsApiController::class, 'trackListen']);
    Route::post('/episodes/{episode:uuid}/track-download', [\App\Http\Controllers\Api\Podcast\AnalyticsApiController::class, 'trackDownload']);
    Route::post('/episodes/{episode:uuid}/track-skip', [\App\Http\Controllers\Api\Podcast\AnalyticsApiController::class, 'trackSkip']);

    Route::get('/my-podcast-subscriptions', [\App\Http\Controllers\Api\Podcast\PodcastApiController::class, 'mySubscriptions']);
    Route::get('/my-listening-queue', [\App\Http\Controllers\Api\Podcast\PlayerApiController::class, 'listeningQueue']);
    Route::get('/my-recent-podcasts', [\App\Http\Controllers\Api\Podcast\PlayerApiController::class, 'recentlyPlayed']);
});
