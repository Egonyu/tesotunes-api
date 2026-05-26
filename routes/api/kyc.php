<?php

use App\Http\Controllers\Api\KycController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| KYC API Routes
|--------------------------------------------------------------------------
|
| User-facing KYC endpoints:
|   GET  /api/kyc/status                 — current state + docs + requirements
|   GET  /api/kyc/requirements/{action}  — what's missing for action
|   POST /api/kyc/documents              — submit a single KYC document
|
| Admin endpoints (require admin/super_admin/moderator role):
|   GET  /api/admin/kyc/pending          — list users awaiting review
|   POST /api/admin/kyc/users/{user}/review — approve or reject submission
|
*/

Route::middleware('auth:sanctum')->prefix('kyc')->name('api.kyc.')->group(function () {
    Route::get('/status', [KycController::class, 'status'])->name('status');
    Route::get('/requirements/{action}', [KycController::class, 'requirements'])->name('requirements');
    Route::post('/documents', [KycController::class, 'uploadDocument'])->name('documents.store');
});

Route::middleware(['auth:sanctum', 'role:admin,super_admin,moderator'])
    ->prefix('admin/kyc')
    ->name('api.admin.kyc.')
    ->group(function () {
        Route::get('/pending', [KycController::class, 'pending'])->name('pending');
        Route::post('/users/{user}/review', [KycController::class, 'review'])->name('review');
    });
