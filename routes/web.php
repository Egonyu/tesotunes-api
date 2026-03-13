<?php

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

// Fallback: return JSON 404 for any undefined web routes and HTTP verbs.
Route::any('/{path?}', function () {
    return response()->json(['message' => 'Not Found'], 404);
})->where('path', '.*');
