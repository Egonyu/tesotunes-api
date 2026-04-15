<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Public API authentication routes (rate limited to prevent brute force)
    Route::middleware('throttle:login')->post('/login', [AuthController::class, 'login']);
    Route::get('/social/providers', [SocialAuthController::class, 'providers']);
    Route::middleware('throttle:login')->post('/social/{provider}/exchange', [SocialAuthController::class, 'exchange']);
    Route::middleware('throttle:login')->post('/local-admin-login', [AuthController::class, 'localAdminLogin']);
    Route::middleware('throttle:register')->post('/register', [AuthController::class, 'register']);

    Route::middleware('throttle:password-recovery')->group(function () {
        Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
        Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
    });

    // Email verification is public when consuming the email link payload.
    Route::middleware('throttle:email-verification')->group(function () {
        Route::post('/email/verify', [EmailVerificationController::class, 'verify']);
        Route::post('/email/resend', [EmailVerificationController::class, 'resend']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::get('/user', [AuthController::class, 'user']);

        Route::get('/email/verify/status', [EmailVerificationController::class, 'status']);
    });
});
