<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Legal Pages API Routes
|--------------------------------------------------------------------------
|
| Routes for managing legal documents, terms of service, privacy policies,
| and other legal documents used by the platform.
|
*/

// Public endpoints (no auth required for fetching published legal pages)
Route::prefix('legal-pages')->name('api.legal-pages.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\LegalPagesController::class, 'index'])->name('index');
    Route::get('/{slug}', [\App\Http\Controllers\Api\LegalPagesController::class, 'show'])->name('show');

    // Acceptance tracking (requires auth)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/{id}/accept', [\App\Http\Controllers\Api\LegalPagesController::class, 'accept'])->name('accept');
        Route::get('/check-acceptance', [\App\Http\Controllers\Api\LegalPagesController::class, 'checkAcceptance'])->name('check-acceptance');
    });
});

// Admin endpoints (requires auth + admin role) — SECURED: Legal pages CRUD
Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])
    ->prefix('admin/legal-pages')
    ->name('api.admin.legal-pages.')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Admin\LegalPagesController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\Api\Admin\LegalPagesController::class, 'store'])->name('store');
        Route::get('/{legalPage}', [\App\Http\Controllers\Api\Admin\LegalPagesController::class, 'show'])->name('show');
        Route::put('/{legalPage}', [\App\Http\Controllers\Api\Admin\LegalPagesController::class, 'update'])->name('update');
        Route::post('/{legalPage}/publish', [\App\Http\Controllers\Api\Admin\LegalPagesController::class, 'publish'])->name('publish');
        Route::post('/{legalPage}/archive', [\App\Http\Controllers\Api\Admin\LegalPagesController::class, 'archive'])->name('archive');
        Route::delete('/{legalPage}', [\App\Http\Controllers\Api\Admin\LegalPagesController::class, 'destroy'])->name('destroy');
        Route::get('/{legalPage}/versions', [\App\Http\Controllers\Api\Admin\LegalPagesController::class, 'getVersions'])->name('versions');
        Route::get('/{legalPage}/acceptances', [\App\Http\Controllers\Api\Admin\LegalPagesController::class, 'getAcceptances'])->name('acceptances');
    });
