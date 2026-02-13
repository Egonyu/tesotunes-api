<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Mobile\MobileDownloadController;
use App\Http\Controllers\Api\Mobile\MobileSyncController;
use App\Http\Controllers\Api\Mobile\MobileSocialController;
use App\Http\Controllers\Api\Mobile\MobileNotificationController;

/*
|--------------------------------------------------------------------------
| Mobile API Routes
|--------------------------------------------------------------------------
|
| Mobile-specific API endpoints for React Native app
| All routes require Sanctum authentication
|
*/

Route::middleware(['auth:sanctum', 'api.rate_limit:100:1'])->prefix('mobile')->name('mobile.')->group(function () {
    
    // Download Management
    Route::prefix('downloads')->name('downloads.')->group(function () {
        Route::get('/check-limit', [MobileDownloadController::class, 'checkDownloadLimit'])->name('check-limit');
        Route::get('/history', [MobileDownloadController::class, 'getDownloadHistory'])->name('history');
        Route::get('/song/{song}', [MobileDownloadController::class, 'getDownloadUrl'])->name('song');
        Route::post('/batch', [MobileDownloadController::class, 'getBatchDownloadUrls'])->name('batch');
        Route::get('/playlist/{playlist}', [MobileDownloadController::class, 'downloadPlaylist'])->name('playlist');
    });
    
    // Data Synchronization
    Route::prefix('sync')->name('sync.')->group(function () {
        Route::post('/full', [MobileSyncController::class, 'fullSync'])->name('full');
        Route::post('/incremental', [MobileSyncController::class, 'incrementalSync'])->name('incremental');
        Route::post('/play-history', [MobileSyncController::class, 'syncPlayHistory'])->name('play-history');
        Route::post('/user-actions', [MobileSyncController::class, 'syncUserActions'])->name('user-actions');
    });
    
    // Social Features
    Route::prefix('social')->name('social.')->group(function () {
        // Feed
        Route::get('/feed', [MobileSocialController::class, 'getFeed'])->name('feed');
        Route::get('/my-posts', [MobileSocialController::class, 'getMyPosts'])->name('my-posts');
        
        // Posts
        Route::post('/posts', [MobileSocialController::class, 'createPost'])->name('posts.create');
        Route::put('/posts/{post}', [MobileSocialController::class, 'updatePost'])->name('posts.update');
        Route::delete('/posts/{post}', [MobileSocialController::class, 'deletePost'])->name('posts.delete');
        Route::post('/posts/{post}/like', [MobileSocialController::class, 'toggleLike'])->name('posts.like');
        
        // Comments
        Route::get('/posts/{post}/comments', [MobileSocialController::class, 'getComments'])->name('comments.index');
        Route::post('/posts/{post}/comments', [MobileSocialController::class, 'addComment'])->name('comments.create');
        Route::delete('/posts/{post}/comments/{comment}', [MobileSocialController::class, 'deleteComment'])->name('comments.delete');
        
        // Notifications
        Route::get('/notifications', [MobileSocialController::class, 'getNotifications'])->name('notifications.index');
        Route::post('/notifications/{notification}/read', [MobileSocialController::class, 'markNotificationRead'])->name('notifications.read');
        Route::post('/notifications/read-all', [MobileSocialController::class, 'markAllNotificationsRead'])->name('notifications.read-all');
    });

    // Push Notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::post('/register-device', [MobileNotificationController::class, 'registerDevice'])->name('register-device');
        Route::get('/preferences', [MobileNotificationController::class, 'getPreferences'])->name('preferences');
        Route::post('/preferences', [MobileNotificationController::class, 'updatePreferences'])->name('update-preferences');
        // Removed test route - use proper notification testing
        Route::get('/devices', [MobileNotificationController::class, 'getDevices'])->name('devices');
        Route::delete('/devices/{device}', [MobileNotificationController::class, 'removeDevice'])->name('remove-device');
    });
});
