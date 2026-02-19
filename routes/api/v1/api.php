<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

// Authentication routes for API tokens
Route::post('/auth/login', [AuthController::class, 'login'])->name('api.auth.login');

// Authenticated auth management routes
Route::middleware(['auth:sanctum'])->prefix('auth')->group(function () {
    Route::post('/tokens', [AuthController::class, 'createToken'])->name('api.auth.create-token');
    Route::delete('/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::delete('/logout-all', [AuthController::class, 'logoutAll'])->name('api.auth.logout-all');
    Route::get('/tokens', [AuthController::class, 'tokens'])->name('api.auth.tokens');
});

// Music streaming routes with optional authentication and rate limiting
// Guests can stream, but authenticated users get higher quality
Route::prefix('tracks')->middleware(['api.rate_limit:30:1'])->group(function () {
    Route::get('/{track}/stream-url', [App\Http\Controllers\Api\MusicController::class, 'getStreamUrl'])
        ->name('api.tracks.stream-url');
    Route::get('/{track}/download-url', [App\Http\Controllers\Api\MusicController::class, 'getDownloadUrl'])
        ->middleware('auth:web') // Downloads require authentication
        ->name('api.tracks.download-url');
});

// Direct file streaming route (within v1 prefix)
Route::get('/stream/{songId}', [App\Http\Controllers\Api\MusicController::class, 'streamFile'])
    ->middleware(['api.rate_limit:30:1'])
    ->name('stream.file');

// Player API routes with optional authentication and stricter rate limiting
// Guests can use player, but play tracking requires authentication
Route::prefix('player')->middleware(['api.rate_limit:20:1'])->group(function () {
    Route::post('/update-now-playing', [App\Http\Controllers\Api\PlayerController::class, 'updateNowPlaying'])
        ->middleware('auth:web') // Requires auth
        ->name('api.player.update-now-playing');
    Route::post('/record-play', [App\Http\Controllers\Api\PlayerController::class, 'recordPlay'])
        ->middleware('auth:web') // Requires auth
        ->name('api.player.record-play');
});

// Public music discovery routes (read-only, limited rate limiting)
Route::prefix('public')->middleware(['api.rate_limit:100:1'])->group(function () {
    Route::get('/tracks', [App\Http\Controllers\Api\MusicController::class, 'publicTracks'])
        ->name('api.public.tracks');
    Route::get('/tracks/{track}', [App\Http\Controllers\Api\MusicController::class, 'publicTrackDetails'])
        ->name('api.public.track-details');
    Route::get('/search', [App\Http\Controllers\Api\DiscoverController::class, 'search'])
        ->name('api.public.search');
    Route::get('/trending', [App\Http\Controllers\Api\DiscoverController::class, 'trending'])
        ->name('api.public.trending');
    Route::get('/genres', [App\Http\Controllers\Api\DiscoverController::class, 'genres'])
        ->name('api.public.genres');
    Route::get('/artists', [App\Http\Controllers\Api\DiscoverController::class, 'artists'])
        ->name('api.public.artists');
    Route::get('/artists/{artist}', [App\Http\Controllers\Api\DiscoverController::class, 'artistDetails'])
        ->name('api.public.artist-details');
    Route::get('/artists/{artist}/songs', [App\Http\Controllers\Api\DiscoverController::class, 'artistSongs'])
        ->name('api.public.artist-songs');
});

// Authenticated API routes with web authentication (session-based)
// Note: CSRF protection is automatically applied to these routes via web middleware group
Route::middleware(['web', 'auth', 'api.rate_limit:60:1'])->group(function () {
    // User authentication check
    Route::get('/user', [\App\Http\Controllers\Api\Auth\AuthController::class, 'user'])->name('api.user');

    // User profile management
    Route::put('/user', [\App\Http\Controllers\Api\User\ProfileController::class, 'update'])->name('api.user.update');
    Route::get('/user/profile', [\App\Http\Controllers\Api\User\ProfileController::class, 'show'])->name('api.user.profile');
    Route::get('/user/library', [\App\Http\Controllers\Api\User\ProfileController::class, 'library'])->name('api.user.library');

    // User's playlists and library
    Route::get('/my/playlists', [App\Http\Controllers\Api\Music\PlaylistController::class, 'myPlaylists'])
        ->name('api.my.playlists');
    // Alias for backward compatibility (some components use /playlists/mine)
    Route::get('/playlists/mine', [App\Http\Controllers\Api\Music\PlaylistController::class, 'myPlaylists'])
        ->name('api.playlists.mine');
    Route::post('/playlists', [App\Http\Controllers\Api\Music\PlaylistController::class, 'store'])
        ->name('api.playlists.store');
    Route::post('/playlists/{playlist}/songs/{song}', [App\Http\Controllers\Api\Music\PlaylistController::class, 'addSong'])
        ->name('api.playlists.add-song');
    Route::delete('/playlists/{playlist}/songs/{song}', [App\Http\Controllers\Api\Music\PlaylistController::class, 'removeSong'])
        ->name('api.playlists.remove-song');
    // Alias for add song using track_id in body (for modal compatibility)
    Route::post('/playlists/{playlist}/tracks', [App\Http\Controllers\Api\Music\PlaylistController::class, 'addSongFromBody'])
        ->name('api.playlists.add-track');
    Route::get('/my/favorites', [App\Http\Controllers\Api\MusicController::class, 'favorites'])
        ->name('api.my.favorites');

    // Song interactions
    Route::post('/songs/{song}/like', [App\Http\Controllers\Api\Music\SongController::class, 'like'])
        ->name('api.songs.like');
    Route::get('/songs/{song}/is-liked', [App\Http\Controllers\Api\Music\SongController::class, 'isLiked'])
        ->name('api.songs.is-liked');
    Route::post('/songs/{song}/play', [App\Http\Controllers\Api\Music\SongController::class, 'recordPlay'])
        ->name('api.songs.play');
    Route::post('/songs/{song}/download', [App\Http\Controllers\Api\Music\SongController::class, 'download'])
        ->name('api.songs.download');

    // Track interactions (aliases for songs)
    Route::post('/tracks/{song}/like', [App\Http\Controllers\Api\Music\SongController::class, 'like'])
        ->name('api.tracks.like');
    Route::get('/tracks/{song}/is-liked', [App\Http\Controllers\Api\Music\SongController::class, 'isLiked'])
        ->name('api.tracks.is-liked');
    Route::post('/tracks/{song}/play', [App\Http\Controllers\Api\Music\SongController::class, 'recordPlay'])
        ->name('api.tracks.play');
    Route::post('/tracks/{song}/download', [App\Http\Controllers\Api\Music\SongController::class, 'download'])
        ->name('api.tracks.download');

    // Artist interactions
    Route::post('/artists/{artist}/follow', [App\Http\Controllers\Api\Music\SimpleArtistController::class, 'follow'])
        ->name('api.artists.follow');
    Route::delete('/artists/{artist}/follow', [App\Http\Controllers\Api\Music\SimpleArtistController::class, 'unfollow'])
        ->name('api.artists.unfollow');

});

// Mobile sync routes removed - use api/mobile.php instead (avoid duplication)

// Debug routes removed from production (use logging or dev environment instead)
