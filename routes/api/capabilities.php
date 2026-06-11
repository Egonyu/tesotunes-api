<?php

use App\Http\Controllers\Api\CapabilityController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Account capability routes
|--------------------------------------------------------------------------
|
| One account, many capabilities (artist, seller, organizer, promoter,
| label). See docs/architecture/CAPABILITIES.md.
|
|   GET  /api/capabilities                       — my capability posture
|   POST /api/capabilities/organizer/apply       — self-service organizer onboarding
|   GET  /api/admin/capabilities/pending         — applications awaiting review
|   POST /api/admin/capabilities/{capability}/review — grant or reject
|
*/

Route::middleware('auth:sanctum')->prefix('capabilities')->name('api.capabilities.')->group(function () {
    Route::get('/', [CapabilityController::class, 'index'])->name('index');
    Route::post('/organizer/apply', [CapabilityController::class, 'applyOrganizer'])->name('organizer.apply');
});

Route::middleware(['auth:sanctum', 'role:admin,super_admin'])
    ->prefix('admin/capabilities')
    ->name('api.admin.capabilities.')
    ->group(function () {
        Route::get('/pending', [CapabilityController::class, 'pending'])->name('pending');
        Route::post('/{capability}/review', [CapabilityController::class, 'review'])->name('review');
    });
