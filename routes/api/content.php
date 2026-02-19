<?php

use App\Http\Controllers\Api\GenreController;
use App\Http\Controllers\Api\MoodController;
use Illuminate\Support\Facades\Route;

// Genre API Routes
Route::prefix('genres')->name('api.genres.')->group(function () {
    Route::get('/', [GenreController::class, 'index'])->name('index');
    Route::get('/{genre}', [GenreController::class, 'show'])->name('show')
        ->where('genre', '[0-9]+');
    Route::get('/{slug}', [GenreController::class, 'showBySlug'])->name('show.slug')
        ->where('slug', '[a-z0-9\-]+');
    Route::get('/{genre}/songs', [GenreController::class, 'songs'])->name('songs');
    Route::get('/{genre}/artists', [GenreController::class, 'artists'])->name('artists');
    Route::get('/{genre}/albums', [GenreController::class, 'albums'])->name('albums');
});

// Content API Routes (legacy prefix — forwards to same controllers)
Route::prefix('content')->name('api.content.')->group(function () {
    // Genres (legacy paths)
    Route::get('/genres', [GenreController::class, 'index'])->name('genres.index');
    Route::get('/genres/{genre}', [GenreController::class, 'show'])->name('genres.show');

    // Moods
    Route::get('/moods', [MoodController::class, 'index'])->name('moods.index');
    Route::get('/moods/{mood}', [MoodController::class, 'show'])->name('moods.show');
});
