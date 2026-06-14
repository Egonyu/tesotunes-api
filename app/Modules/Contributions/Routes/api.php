<?php

use App\Modules\Contributions\Http\Controllers\Api\ContributionConsentController;
use Illuminate\Support\Facades\Route;

/*
| Contributions module API routes. Mounted under /api/contributions by the
| module provider, behind auth:sanctum. Only loaded when CONTRIBUTIONS_ENABLED.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/consent', [ContributionConsentController::class, 'show'])->name('consent.show');
    Route::post('/consent', [ContributionConsentController::class, 'store'])->name('consent.store');
});
