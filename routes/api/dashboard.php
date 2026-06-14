<?php

use App\Http\Controllers\Api\DashboardController;
use Illuminate\Support\Facades\Route;

// Unified account dashboard for every authenticated user.
Route::prefix('dashboard')->middleware('auth:sanctum')->name('api.dashboard.')->group(function () {
    Route::get('/overview', [DashboardController::class, 'overview'])->name('overview');
});
