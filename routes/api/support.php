<?php

use App\Http\Controllers\Api\SupportMessageController;
use Illuminate\Support\Facades\Route;

// Lightweight "contact the team" channel — delivers a user's message to all
// admins/moderators as in-app notifications. Throttled to curb spam.
Route::middleware(['auth:sanctum', 'throttle:6,1'])->group(function () {
    Route::post('/support/messages', [SupportMessageController::class, 'store'])
        ->name('api.support.messages.store');
});
