<?php

use Illuminate\Support\Facades\Route;

// Cross-Module Notification API Routes
Route::middleware('auth:sanctum')->prefix('notifications')->name('api.notifications.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\NotificationController::class, 'index'])->name('index');
    Route::get('/unread-counts', [\App\Http\Controllers\Api\NotificationController::class, 'unreadCounts'])->name('unread-counts');
    Route::get('/recent', [\App\Http\Controllers\Api\NotificationController::class, 'recent'])->name('recent');
    Route::get('/settings', [\App\Http\Controllers\Api\NotificationController::class, 'settings'])->name('settings');
    Route::put('/settings', [\App\Http\Controllers\Api\NotificationController::class, 'updateSettings'])->name('update-settings');
    Route::post('/mark-all-read', [\App\Http\Controllers\Api\NotificationController::class, 'markAllAsRead'])->name('mark-all-read');
    Route::post('/{notification}/mark-read', [\App\Http\Controllers\Api\NotificationController::class, 'markAsRead'])->name('mark-read');
    Route::delete('/{notification}', [\App\Http\Controllers\Api\NotificationController::class, 'destroy'])->name('delete');

    // Admin only routes
    Route::middleware('role:admin,super_admin')->group(function () {
        Route::get('/analytics', [\App\Http\Controllers\Api\NotificationController::class, 'analytics'])->name('analytics');
        Route::get('/health', [\App\Http\Controllers\Api\NotificationController::class, 'health'])->name('health');
        Route::post('/preview', [\App\Http\Controllers\Api\NotificationController::class, 'preview'])->name('preview');
    });
});

// Device Token Management (Push Notifications)
Route::middleware('auth:sanctum')->prefix('device-tokens')->name('api.device-tokens.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\DeviceTokenController::class, 'index'])->name('index');
    Route::post('/', [\App\Http\Controllers\Api\DeviceTokenController::class, 'store'])->name('store');
    Route::delete('/{id}', [\App\Http\Controllers\Api\DeviceTokenController::class, 'destroy'])->name('destroy');
    Route::post('/deactivate-all', [\App\Http\Controllers\Api\DeviceTokenController::class, 'deactivateAll'])->name('deactivate-all');
});
