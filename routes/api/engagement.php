<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Engagement API Routes
|--------------------------------------------------------------------------
|
| Polls, Awards, and community engagement features.
|
*/

// Poll creation + voting (auth required)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/polls', [\App\Http\Controllers\Api\UserPollController::class, 'store'])
        ->name('api.polls.store');
    Route::post('/polls/{poll}/vote', [\App\Http\Controllers\Api\PollVoteController::class, 'vote'])
        ->name('api.polls.vote');
    Route::post('/tips', [\App\Http\Controllers\Api\TipController::class, 'store'])
        ->name('api.tips.store');
});

// Poll listing & results (public)
Route::get('/polls', [\App\Http\Controllers\Api\PollVoteController::class, 'index'])
    ->name('api.polls.index');
Route::get('/polls/{poll}/results', [\App\Http\Controllers\Api\PollVoteController::class, 'results'])
    ->name('api.polls.results');

// Artists search (public)
Route::get('/artists', [\App\Http\Controllers\Api\Music\ArtistController::class, 'index'])
    ->name('api.artists.index');

// Awards API Routes (public)
Route::prefix('awards')->name('api.awards.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\AwardsApiController::class, 'index'])->name('index');
    Route::get('/current-season', [\App\Http\Controllers\Api\AwardsApiController::class, 'currentSeason'])->name('current-season');
    Route::get('/{id}', [\App\Http\Controllers\Api\AwardsApiController::class, 'show'])->name('show');
    Route::get('/{id}/categories', [\App\Http\Controllers\Api\AwardsApiController::class, 'categories'])->name('categories');
    Route::get('/{id}/categories/{categoryId}/nominations', [\App\Http\Controllers\Api\AwardsApiController::class, 'nominations'])->name('nominations');
    Route::get('/{id}/results', [\App\Http\Controllers\Api\AwardsApiController::class, 'results'])->name('results');
});

// Awards API Routes (auth required)
Route::middleware('auth:sanctum')->prefix('awards')->name('api.awards.auth.')->group(function () {
    Route::post('/{id}/nominations', [\App\Http\Controllers\Api\AwardsApiController::class, 'submitNomination'])->name('nominate');
    Route::post('/{id}/vote', [\App\Http\Controllers\Api\AwardsApiController::class, 'vote'])->name('vote');
});
