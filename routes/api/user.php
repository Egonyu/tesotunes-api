<?php

use Illuminate\Support\Facades\Route;

// User settings, profile management, and activity interactions
Route::middleware('auth:sanctum')->group(function () {
    // User settings (audio, notifications, appearance, privacy, etc.)
    Route::get('/settings', [\App\Http\Controllers\Api\Settings\UserSettingsController::class, 'index'])->name('api.settings.index');
    Route::put('/settings', [\App\Http\Controllers\Api\Settings\UserSettingsController::class, 'update'])->name('api.settings.update');

    Route::prefix('settings')->group(function () {
        Route::get('/2fa', [\App\Http\Controllers\Api\Settings\TwoFactorSettingsController::class, 'status']);
        Route::post('/2fa/enable', [\App\Http\Controllers\Api\Settings\TwoFactorSettingsController::class, 'enable']);
        Route::post('/2fa/verify', [\App\Http\Controllers\Api\Settings\TwoFactorSettingsController::class, 'verify']);
        Route::post('/2fa/disable', [\App\Http\Controllers\Api\Settings\TwoFactorSettingsController::class, 'disable']);
        Route::post('/2fa/recovery-codes', [\App\Http\Controllers\Api\Settings\TwoFactorSettingsController::class, 'regenerateRecoveryCodes']);
    });

    // User profile management
    Route::put('/user', [\App\Http\Controllers\Api\User\ProfileController::class, 'update'])
        ->name('api.user.update.sanctum');
    Route::get('/user/profile', [\App\Http\Controllers\Api\User\ProfileController::class, 'show'])
        ->name('api.user.profile.sanctum');
    Route::get('/user/library', [\App\Http\Controllers\Api\User\ProfileController::class, 'library'])
        ->name('api.user.library.sanctum');

    // Like/Unlike any entity
    Route::post('/like/{type}/{id}', [\App\Http\Controllers\Api\ActivityInteractionController::class, 'toggleLike'])
        ->name('api.like.toggle');

    // Like status for any entity
    Route::get('/like/{type}/{id}/status', [\App\Http\Controllers\Api\ActivityInteractionController::class, 'likeStatus'])
        ->name('api.like.status');

    // Bookmark/Unbookmark any entity
    Route::post('/bookmark/{type}/{id}', [\App\Http\Controllers\Api\ActivityInteractionController::class, 'toggleBookmark'])
        ->name('api.bookmark.toggle');

    // Event interest
    Route::post('/events/{id}/interest', [\App\Http\Controllers\Api\ActivityInteractionController::class, 'toggleEventInterest'])
        ->name('api.events.interest');
});
