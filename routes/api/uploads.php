<?php

use Illuminate\Support\Facades\Route;

// File Upload API Routes (authenticated)
Route::middleware('auth:sanctum')->prefix('uploads')->name('api.uploads.')->group(function () {
    Route::post('/audio', [\App\Http\Controllers\Api\Upload\FileController::class, 'uploadAudio'])->name('audio');
    Route::post('/image', [\App\Http\Controllers\Api\Upload\FileController::class, 'uploadImage'])->name('image');
    Route::post('/avatar', [\App\Http\Controllers\Api\Upload\FileController::class, 'uploadAvatar'])->name('avatar');
});
