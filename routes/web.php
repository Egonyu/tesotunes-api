<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (API Backend)
|--------------------------------------------------------------------------
|
| This API backend has minimal web routes. All application logic is
| served via routes/api.php. These web routes exist only for:
| - Auth endpoints that need CSRF exemption (NextAuth integration)
| - Health check fallback
|
*/

// Include auth routes (CSRF-exempt login/register for NextAuth)
require __DIR__.'/auth.php';

// Fallback: return JSON 404 for any undefined web routes
Route::fallback(function () {
    return response()->json(['message' => 'Not Found'], 404);
});
