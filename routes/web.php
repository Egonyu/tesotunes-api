<?php

use App\Http\Controllers\Api\Auth\EmailVerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (API Backend)
|--------------------------------------------------------------------------
|
| This API backend has minimal web routes. All application logic is
| served via routes/api.php. Web traffic falls through to a JSON 404
| unless an explicit web route is introduced for a deliberate reason.
|
*/

Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'redirect'])
    ->name('verification.verify');

// Fallback: return JSON 404 for undefined read-only web routes.
Route::get('/{path?}', function () {
    return response()->json(['message' => 'Not Found'], 404);
})->where('path', '.*');
