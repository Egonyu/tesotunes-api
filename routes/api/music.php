<?php

use App\Http\Controllers\Api\Music\AlbumController;
use App\Http\Controllers\Api\Music\ArtistController;
use App\Http\Controllers\Api\Music\PlaylistController;
use App\Http\Controllers\Api\Music\SongController;
use App\Http\Controllers\Api\MusicApiController;
use Illuminate\Support\Facades\Route;

// Public Music API Endpoints
Route::prefix('')->group(function () {
    // Songs — standardized via SongController + SongResource
    Route::get('/songs', [SongController::class, 'index'])->name('api.music.songs');
    Route::get('/songs/{song}', [SongController::class, 'show'])->name('api.music.song');

    // Artists — standardized via ArtistController + ArtistResource
    Route::get('/artists', [ArtistController::class, 'index'])->name('api.music.artists');
    Route::get('/artists/{artist}', [ArtistController::class, 'show'])->name('api.music.artist');
    Route::get('/artists/{artist}/songs', [ArtistController::class, 'songs'])->name('api.music.artist.songs');
    Route::get('/artists/{artist}/albums', [ArtistController::class, 'albums'])->name('api.music.artist.albums');

    // Albums — standardized via AlbumController + AlbumResource
    Route::get('/albums', [AlbumController::class, 'index'])->name('api.music.albums');
    Route::get('/albums/{album}', [AlbumController::class, 'show'])->name('api.music.album');
    Route::get('/albums/{album}/tracks', [AlbumController::class, 'tracks'])->name('api.music.album.tracks');

    // Trending
    Route::get('/trending', [MusicApiController::class, 'trending'])->name('api.music.trending');

    // Playlists — standardized via PlaylistController + PlaylistResource
    Route::get('/playlists/featured', [PlaylistController::class, 'featured'])->name('api.music.playlists.featured');
    Route::get('/playlists', [PlaylistController::class, 'index'])->name('api.music.playlists');
    Route::get('/playlists/{playlist}', [PlaylistController::class, 'show'])->name('api.music.playlist');
    Route::get('/playlists/{playlist}/tracks', [PlaylistController::class, 'tracks'])->name('api.music.playlist.tracks');
});

// Admin routes for artists management — SECURED with auth + role middleware
Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->prefix('admin')->group(function () {
    Route::get('/artists', [\App\Http\Controllers\Api\Admin\AdminArtistsController::class, 'index']);
    Route::get('/artists/statistics', [\App\Http\Controllers\Api\Admin\AdminArtistsController::class, 'statistics']);
    Route::get('/artists/{id}', [\App\Http\Controllers\Api\Admin\AdminArtistsController::class, 'show']);
    Route::post('/artists/{id}', [\App\Http\Controllers\Api\Admin\AdminArtistsController::class, 'update']);
    Route::delete('/artists/{id}', [\App\Http\Controllers\Api\Admin\AdminArtistsController::class, 'destroy']);
    Route::post('/artists/{id}/verify', [\App\Http\Controllers\Api\Admin\AdminArtistsController::class, 'verify']);
    Route::post('/artists/{id}/toggle-verify', [\App\Http\Controllers\Api\Admin\AdminArtistsController::class, 'toggleVerify']);
    Route::post('/artists/{id}/toggle-featured', [\App\Http\Controllers\Api\Admin\AdminArtistsController::class, 'toggleFeatured']);
    Route::post('/artists/{id}/status', [\App\Http\Controllers\Api\Admin\AdminArtistsController::class, 'updateStatus']);
    Route::post('/artists/{id}/approve', [\App\Http\Controllers\Api\Admin\AdminArtistsController::class, 'approve']);
    Route::post('/artists/{id}/suspend', [\App\Http\Controllers\Api\Admin\AdminArtistsController::class, 'suspend']);
});

// Admin users routes — SECURED with auth + role middleware
Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->prefix('admin')->group(function () {
    Route::get('/users', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'index']);
    Route::get('/users/statistics', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'statistics']);
    Route::get('/users/{id}', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'show']);
});
