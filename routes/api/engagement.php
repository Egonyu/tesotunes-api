<?php

use App\Http\Controllers\Api\AwardsApiController;
use App\Http\Controllers\Api\Music\ArtistController;
use App\Http\Controllers\Api\PollController;
use App\Http\Controllers\Api\PollResponseController;
use App\Http\Controllers\Api\TipController;
use App\Http\Controllers\Api\UserPollController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Engagement API Routes
|--------------------------------------------------------------------------
|
| Polls, surveys, awards, tips — community and research engagement features.
|
*/

// ── Polls ─────────────────────────────────────────────────────────────────
// NOTE: Static routes (/my) must be registered BEFORE the wildcard /{poll}
// route, otherwise Laravel matches "my" as a poll model binding and returns 404.
Route::prefix('polls')->name('api.polls.')->group(function () {
    Route::get('/', [PollController::class, 'index'])->name('index');
    Route::middleware('auth:sanctum')->post('/', [UserPollController::class, 'store'])->name('store');
    Route::middleware('auth:sanctum')->get('/my', [UserPollController::class, 'myPolls'])->name('my');

    // Wildcard routes come after all static segments
    Route::get('/{poll}', [PollController::class, 'show'])->name('show');
    Route::get('/{poll}/results', [PollController::class, 'results'])->name('results');
    Route::middleware('auth:sanctum')->post('/{poll}/respond', [PollResponseController::class, 'respond'])->name('respond');
});

// ── Tips — Authenticated ──────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/tips', [TipController::class, 'store'])->name('api.tips.store');
});

// ── Artists search — Public ───────────────────────────────────────────────
Route::get('/artists', [ArtistController::class, 'index'])->name('api.artists.index');

// ── Awards — Public ───────────────────────────────────────────────────────
Route::prefix('awards')->name('api.awards.')->group(function () {
    Route::get('/', [AwardsApiController::class, 'index'])->name('index');
    Route::get('/current-season', [AwardsApiController::class, 'currentSeason'])->name('current-season');
    Route::get('/{id}', [AwardsApiController::class, 'show'])->name('show');
    Route::get('/{id}/categories', [AwardsApiController::class, 'categories'])->name('categories');
    Route::get('/{id}/categories/{categoryId}/nominations', [AwardsApiController::class, 'nominations'])->name('nominations');
    Route::get('/{id}/results', [AwardsApiController::class, 'results'])->name('results');
});

// ── Awards — Authenticated ────────────────────────────────────────────────
Route::middleware('auth:sanctum')->prefix('awards')->name('api.awards.auth.')->group(function () {
    Route::post('/{id}/nominations', [AwardsApiController::class, 'submitNomination'])->name('nominate');
    Route::post('/{id}/vote', [AwardsApiController::class, 'vote'])->name('vote');
});
