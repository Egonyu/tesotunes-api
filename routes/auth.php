<?php

/*
|--------------------------------------------------------------------------
| Web Auth Routes (API Backend)
|--------------------------------------------------------------------------
|
| Minimal auth routes for the API backend. The primary auth endpoints
| live in routes/api/auth.php under the /api prefix.
|
| These web-based auth routes exist only for:
| - CSRF-exempt login/register for NextAuth (SPA frontend)
| - Any OAuth callback flows that require web middleware
|
*/

use App\Http\Controllers\Api\Auth\AuthController as ApiAuthController;
use Illuminate\Support\Facades\Route;

// API Login/Register endpoints (no CSRF - for NextAuth and mobile apps)
// Returns JSON responses with Sanctum tokens — rate limited
Route::middleware('throttle:login')->post('/auth/login', [ApiAuthController::class, 'login'])->name('auth.login');
Route::middleware('throttle:register')->post('/auth/register', [ApiAuthController::class, 'register'])->name('auth.register');
