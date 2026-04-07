<?php

use App\Http\Controllers\Api\Music\AlbumController;
use App\Http\Controllers\Api\Music\ArtistController;
use App\Http\Controllers\Api\Music\PlaylistController;
use App\Http\Controllers\Api\Music\SongController;
use Illuminate\Support\Facades\Route;

// Public Music API Endpoints
Route::prefix('')->group(function () {
    // Songs — standardized via SongController + SongResource
    Route::get('/songs', [SongController::class, 'index'])->name('api.music.songs');
    Route::get('/songs/{song}', [SongController::class, 'show'])->name('api.music.song');

    // Artists — standardized via ArtistController + ArtistResource
    Route::get('/artists', [ArtistController::class, 'index'])->name('api.music.artists');
    Route::get('/artists/{artist}', [ArtistController::class, 'show'])->name('api.music.artist');
    Route::get('/artists/{artist}/events', [ArtistController::class, 'events'])->name('api.music.artist.events');
    Route::get('/artists/{artist}/songs', [ArtistController::class, 'songs'])->name('api.music.artist.songs');
    Route::get('/artists/{artist}/albums', [ArtistController::class, 'albums'])->name('api.music.artist.albums');

    // Albums — standardized via AlbumController + AlbumResource
    Route::get('/albums', [AlbumController::class, 'index'])->name('api.music.albums');
    Route::get('/albums/{album}', [AlbumController::class, 'show'])->name('api.music.album');
    Route::get('/albums/{album}/tracks', [AlbumController::class, 'tracks'])->name('api.music.album.tracks');

    // Trending
    Route::get('/trending', [SongController::class, 'trending'])->name('api.music.trending');

    // Playlists — standardized via PlaylistController + PlaylistResource
    Route::get('/playlists/featured', [PlaylistController::class, 'featured'])->name('api.music.playlists.featured');
    Route::get('/playlists', [PlaylistController::class, 'index'])->name('api.music.playlists');
    Route::get('/playlists/invites/{token}', [PlaylistController::class, 'invitePreview'])->name('api.music.playlists.invites.show');
    Route::middleware('auth:sanctum')->get('/playlists/mine', [PlaylistController::class, 'myPlaylists'])->name('api.music.playlists.mine');
    Route::get('/playlists/{playlist}', [PlaylistController::class, 'show'])->name('api.music.playlist');
    Route::get('/playlists/{playlist}/tracks', [PlaylistController::class, 'tracks'])->name('api.music.playlist.tracks');
    Route::get('/playlists/{playlist}/suggested-songs', [PlaylistController::class, 'suggestedSongs'])->name('api.music.playlists.suggested');
});

// Playlist CRUD — authenticated users
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/playlists', [PlaylistController::class, 'store'])->name('api.music.playlists.store');
    Route::put('/playlists/{playlist}', [PlaylistController::class, 'update'])->name('api.music.playlists.update');
    Route::delete('/playlists/{playlist}', [PlaylistController::class, 'destroy'])->name('api.music.playlists.destroy');
    Route::delete('/playlists/{playlist}/artwork', [PlaylistController::class, 'removeArtwork'])->name('api.music.playlists.artwork.destroy');
    Route::post('/playlists/{playlist}/songs/{song}', [PlaylistController::class, 'addSong'])->name('api.music.playlists.add-song');
    Route::post('/playlists/{playlist}/tracks', [PlaylistController::class, 'addSongFromBody'])->name('api.music.playlists.add-track');
    Route::delete('/playlists/{playlist}/songs/{song}', [PlaylistController::class, 'removeSong'])->name('api.music.playlists.remove-song');
    Route::post('/playlists/{playlist}/reorder', [PlaylistController::class, 'reorderSongs'])->name('api.music.playlists.reorder');
    Route::get('/playlists/{playlist}/follow/status', [PlaylistController::class, 'followStatus'])->name('api.music.playlists.follow.status');
    Route::post('/playlists/{playlist}/follow', [PlaylistController::class, 'toggleFollow'])->name('api.music.playlists.follow');
    Route::delete('/playlists/{playlist}/follow', [PlaylistController::class, 'toggleFollow'])->name('api.music.playlists.unfollow');
    Route::get('/playlists/{playlist}/collaborators', [PlaylistController::class, 'collaborators'])->name('api.music.playlists.collaborators');
    Route::post('/playlists/{playlist}/collaborators', [PlaylistController::class, 'addCollaborator'])->name('api.music.playlists.collaborators.store');
    Route::post('/playlists/{playlist}/collaborators/{collaborator}/approve', [PlaylistController::class, 'approveCollaborator'])->name('api.music.playlists.collaborators.approve');
    Route::post('/playlists/{playlist}/collaborators/{collaborator}/role', [PlaylistController::class, 'updateCollaboratorRole'])->name('api.music.playlists.collaborators.role');
    Route::delete('/playlists/{playlist}/collaborators/{collaborator}', [PlaylistController::class, 'removeCollaborator'])->name('api.music.playlists.collaborators.destroy');
    Route::post('/playlists/{playlist}/collaborative', [PlaylistController::class, 'setCollaborative'])->name('api.music.playlists.collaborative');
    Route::post('/playlists/{playlist}/invite-link', [PlaylistController::class, 'generateInviteLink'])->name('api.music.playlists.invite-link');
    Route::post('/playlists/invites/{token}/join', [PlaylistController::class, 'joinInvite'])->name('api.music.playlists.invites.join');
});

// Admin routes for artists management — SECURED with auth + role middleware
Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])->prefix('admin')->group(function () {
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
    Route::post('/artists/{id}/reject', [\App\Http\Controllers\Api\Admin\AdminArtistsController::class, 'reject']);
    Route::post('/artists/{id}/suspend', [\App\Http\Controllers\Api\Admin\AdminArtistsController::class, 'suspend']);
});

// Admin users routes — SECURED with auth + role middleware
Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])->prefix('admin')->group(function () {
    Route::get('/users', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'index']);
    Route::get('/users/statistics', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'statistics']);
    Route::get('/users/{id}', [\App\Http\Controllers\Api\Admin\AdminUsersController::class, 'show']);
});
