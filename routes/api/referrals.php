<?php

use App\Http\Controllers\Api\ReferralController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Referral Programme — member facing
|--------------------------------------------------------------------------
|
| The screens under /referrals were built against these endpoints and
| shipped without them, so every call 404'd. Signup rewards were paying out
| the whole time; members simply had no way to see it, share a link, or
| climb the milestone ladder.
|
| Note these are member routes. /api/artist/referrals/* is a separate,
| artist-specific fan-referral surface and is left alone.
|
*/

Route::prefix('referrals')->name('api.referrals.')->group(function () {
    // Public: the register screen checks a code before an account exists.
    Route::get('/validate/{code}', [ReferralController::class, 'validateCode'])
        ->middleware('throttle:30,1')
        ->name('validate');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/dashboard', [ReferralController::class, 'dashboard'])->name('dashboard');
        Route::get('/code', [ReferralController::class, 'code'])->name('code');
        Route::get('/history', [ReferralController::class, 'history'])->name('history');
        Route::get('/rewards', [ReferralController::class, 'rewards'])->name('rewards');
        Route::post('/rewards/{milestone}/claim', [ReferralController::class, 'claim'])->name('rewards.claim');
        Route::get('/leaderboard', [ReferralController::class, 'leaderboard'])->name('leaderboard');
        Route::post('/share', [ReferralController::class, 'trackShare'])->name('share');
    });
});
