<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use Illuminate\Support\Facades\Route;

// Public API authentication routes (rate limited to prevent brute force)
Route::middleware('throttle:login')->post('/login', [AuthController::class, 'login']);
Route::middleware('throttle:register')->post('/register', [AuthController::class, 'register']);

// Password Reset (public — rate limited)
Route::middleware('throttle:login')->group(function () {
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
});

// Email Verification (public — verify endpoint, no auth needed for clicking email link)
Route::middleware('throttle:login')->post('/email/verify', [EmailVerificationController::class, 'verify']);

// Authenticated auth routes (require valid Sanctum token)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/user', [AuthController::class, 'user']);

    // Email verification (authenticated)
    Route::post('/email/resend', [EmailVerificationController::class, 'resend']);
    Route::get('/email/verify/status', [EmailVerificationController::class, 'status']);
});
