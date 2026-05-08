<?php

use App\Http\Controllers\Api\Auth\AuthController;
use Illuminate\Support\Facades\Route;

// Development-only routes — never registered in production.
// Included from api.php only when app()->environment('local').

Route::prefix('auth')->group(function () {
    Route::middleware('throttle:login')->post('/local-admin-login', [AuthController::class, 'localAdminLogin']);
});
