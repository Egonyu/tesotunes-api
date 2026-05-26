<?php

use Illuminate\Support\Facades\Route;

// Artist API Routes — Dashboard, Songs, Albums, Profile, Earnings, Analytics
// SECURED: Requires auth + artist/admin role (HIGH-5 fix)
Route::middleware(['auth:sanctum', 'role:artist,admin,super_admin'])->prefix('artist')->name('api.artist.')->group(function () {
    // Dashboard
    Route::get('/dashboard', [\App\Http\Controllers\Api\ArtistApiController::class, 'dashboard'])->name('dashboard');

    // Songs CRUD
    Route::get('/songs', [\App\Http\Controllers\Api\ArtistApiController::class, 'songs'])->name('songs.index');
    Route::post('/songs/upload-target', [\App\Http\Controllers\Api\ArtistApiController::class, 'createSongUploadTarget'])->name('songs.upload-target');
    Route::post('/songs/upload-sessions', [\App\Http\Controllers\Api\ArtistApiController::class, 'createSongUploadSession'])->name('songs.upload-sessions.store');
    Route::post('/songs/upload-sessions/{session}/parts', [\App\Http\Controllers\Api\ArtistApiController::class, 'createSongUploadSessionPartTarget'])->name('songs.upload-sessions.parts');
    Route::post('/songs/upload-sessions/{session}/parts/{part}/verify', [\App\Http\Controllers\Api\ArtistApiController::class, 'verifySongUploadSessionPart'])->name('songs.upload-sessions.parts.verify');
    Route::post('/songs/upload-sessions/{session}/complete', [\App\Http\Controllers\Api\ArtistApiController::class, 'completeSongUploadSession'])->name('songs.upload-sessions.complete');
    Route::post('/songs/upload-sessions/{session}/abort', [\App\Http\Controllers\Api\ArtistApiController::class, 'abortSongUploadSession'])->name('songs.upload-sessions.abort');
    Route::post('/songs', [\App\Http\Controllers\Api\ArtistApiController::class, 'storeSong'])->name('songs.store');
    Route::get('/songs/{id}', [\App\Http\Controllers\Api\ArtistApiController::class, 'showSong'])->name('songs.show');
    Route::put('/songs/{id}', [\App\Http\Controllers\Api\ArtistApiController::class, 'updateSong'])->name('songs.update');
    Route::delete('/songs/{id}', [\App\Http\Controllers\Api\ArtistApiController::class, 'deleteSong'])->name('songs.destroy');
    Route::post('/songs/bulk-delete', [\App\Http\Controllers\Api\ArtistApiController::class, 'bulkDeleteSongs'])->name('songs.bulkDelete');
    Route::post('/songs/bulk-status', [\App\Http\Controllers\Api\ArtistApiController::class, 'bulkUpdateSongStatus'])->name('songs.bulkStatus');

    // Albums
    Route::get('/albums', [\App\Http\Controllers\Api\ArtistApiController::class, 'albums'])->name('albums.index');
    Route::post('/albums', [\App\Http\Controllers\Api\ArtistApiController::class, 'storeAlbum'])->name('albums.store');
    Route::get('/albums/{id}', [\App\Http\Controllers\Api\ArtistApiController::class, 'showAlbum'])->name('albums.show');
    Route::put('/albums/{id}', [\App\Http\Controllers\Api\ArtistApiController::class, 'updateAlbum'])->name('albums.update');

    // Profile
    Route::get('/profile', [\App\Http\Controllers\Api\ArtistApiController::class, 'profile'])->name('profile.show');
    Route::put('/profile', [\App\Http\Controllers\Api\ArtistApiController::class, 'updateProfile'])->name('profile.update');
    Route::post('/profile/avatar', [\App\Http\Controllers\Api\ArtistApiController::class, 'uploadProfileAvatar'])->name('profile.avatar');
    Route::post('/profile/banner', [\App\Http\Controllers\Api\ArtistApiController::class, 'uploadProfileBanner'])->name('profile.banner');

    // Earnings
    Route::get('/earnings', [\App\Http\Controllers\Api\ArtistApiController::class, 'earnings'])->name('earnings.index');
    Route::get('/earnings/songs', [\App\Http\Controllers\Api\ArtistApiController::class, 'perSongEarnings'])->name('earnings.songs');
    Route::get('/earnings/payouts', [\App\Http\Controllers\Api\ArtistApiController::class, 'payoutHistory'])->name('earnings.payouts');
    Route::post('/earnings/withdraw', [\App\Http\Controllers\Api\ArtistApiController::class, 'withdraw'])
        ->middleware('kyc:withdrawal')
        ->name('earnings.withdraw');

    // Royalty Splits
    Route::get('/royalty-splits', [\App\Http\Controllers\Api\ArtistApiController::class, 'royaltySplits'])->name('royalty-splits.index');

    // Analytics
    Route::get('/analytics', [\App\Http\Controllers\Api\ArtistApiController::class, 'analytics'])->name('analytics');

    // Referrals
    Route::get('/referrals/dashboard', [\App\Http\Controllers\Api\ArtistApiController::class, 'referralsDashboard'])->name('referrals.dashboard');
    Route::get('/referrals/link', [\App\Http\Controllers\Api\ArtistApiController::class, 'referralLink'])->name('referrals.link');
    Route::get('/referrals/fans', [\App\Http\Controllers\Api\ArtistApiController::class, 'referralFans'])->name('referrals.fans');
    Route::get('/referrals/earnings', [\App\Http\Controllers\Api\ArtistApiController::class, 'referralEarnings'])->name('referrals.earnings');
    Route::get('/referrals/promo-materials', [\App\Http\Controllers\Api\ArtistApiController::class, 'promoMaterials'])->name('referrals.promo');
    Route::post('/referrals/promo-materials/generate', [\App\Http\Controllers\Api\ArtistApiController::class, 'generatePromoMaterial'])->name('referrals.promoGenerate');
    Route::post('/referrals/share', [\App\Http\Controllers\Api\ArtistApiController::class, 'trackShare'])->name('referrals.share');
});

// Catalog — authenticated user catalog submissions and claim requests
Route::middleware('auth:sanctum')->prefix('catalog')->name('api.catalog.')->group(function () {
    Route::post('/submissions', [\App\Http\Controllers\Api\CatalogSubmissionController::class, 'store'])->name('submissions.store');
    Route::get('/submissions', [\App\Http\Controllers\Api\CatalogSubmissionController::class, 'index'])->name('submissions.index');
    Route::get('/submissions/{submission}', [\App\Http\Controllers\Api\CatalogSubmissionController::class, 'show'])->name('submissions.show');
    Route::get('/claim-requests', [\App\Http\Controllers\Api\CatalogClaimRequestController::class, 'index'])->name('claims.index');
    Route::post('/claim-requests', [\App\Http\Controllers\Api\CatalogClaimRequestController::class, 'store'])
        ->middleware('kyc:music_claim')
        ->name('claims.store');
});

// Public catalog browse (no auth)
Route::get('/catalog/claimable-artists', [\App\Http\Controllers\Api\Music\ArtistController::class, 'index'])->name('api.catalog.claimable-artists');

// Admin catalog moderation
Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])->prefix('admin/catalog')->name('api.admin.catalog.')->group(function () {
    Route::get('/claim-requests', [\App\Http\Controllers\Api\Admin\CatalogClaimRequestAdminController::class, 'index'])->name('claims.index');
    Route::post('/claim-requests/{claim}/approve', [\App\Http\Controllers\Api\Admin\CatalogClaimRequestAdminController::class, 'approve'])->name('claims.approve');
    Route::post('/claim-requests/{claim}/reject', [\App\Http\Controllers\Api\Admin\CatalogClaimRequestAdminController::class, 'reject'])->name('claims.reject');
});
