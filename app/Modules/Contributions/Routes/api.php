<?php

use App\Modules\Contributions\Http\Controllers\Api\ContributionConsentController;
use App\Modules\Contributions\Http\Controllers\Api\ContributionTaskController;
use App\Modules\Contributions\Http\Controllers\Api\ContributionValidationController;
use App\Modules\Contributions\Http\Controllers\Api\LyricOptInController;
use Illuminate\Support\Facades\Route;

/*
| Contributions module API routes. Mounted under /api/contributions by the
| module provider, behind auth:sanctum. Only loaded when CONTRIBUTIONS_ENABLED.
*/

Route::middleware('auth:sanctum')->group(function () {
    // Data-terms consent (9.1)
    Route::get('/consent', [ContributionConsentController::class, 'show'])->name('consent.show');
    Route::post('/consent', [ContributionConsentController::class, 'store'])->name('consent.store');

    // Contributor translation tasks (9.2)
    Route::get('/tasks', [ContributionTaskController::class, 'index'])->name('tasks.index');
    Route::post('/tasks/{task}/submit', [ContributionTaskController::class, 'submit'])->name('tasks.submit');

    // Artist per-song lyric opt-in (9.2)
    Route::get('/songs/{song}/optin', [LyricOptInController::class, 'show'])->name('songs.optin.show');
    Route::post('/songs/{song}/optin', [LyricOptInController::class, 'store'])->name('songs.optin.store');
    Route::delete('/songs/{song}/optin', [LyricOptInController::class, 'destroy'])->name('songs.optin.destroy');

    // Peer validation + quality gate (9.3)
    Route::get('/validations/queue', [ContributionValidationController::class, 'queue'])->name('validations.queue');
    Route::post('/submissions/{submission}/validate', [ContributionValidationController::class, 'store'])->name('submissions.validate');
});
