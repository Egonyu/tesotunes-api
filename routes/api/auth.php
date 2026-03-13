<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Public API authentication routes (rate limited to prevent brute force)
    Route::middleware('throttle:login')->post('/login', [AuthController::class, 'login']);
    Route::middleware('throttle:register')->post('/register', [AuthController::class, 'register']);

    // Password reset routes share login-style throttling because they are account-targeted.
    Route::middleware('throttle:login')->group(function () {
        Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
        Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
    });

    // Email verification is public when consuming the email link payload.
    Route::middleware('throttle:login')->post('/email/verify', [EmailVerificationController::class, 'verify']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::get('/user', [AuthController::class, 'user']);

        Route::post('/email/resend', [EmailVerificationController::class, 'resend']);
        Route::get('/email/verify/status', [EmailVerificationController::class, 'status']);
    });
});
