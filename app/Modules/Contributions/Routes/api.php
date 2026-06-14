<?php

use App\Modules\Contributions\Http\Controllers\Api\ContributionAdminController;
use App\Modules\Contributions\Http\Controllers\Api\ContributionConsentController;
use App\Modules\Contributions\Http\Controllers\Api\ContributionTaskController;
use App\Modules\Contributions\Http\Controllers\Api\ContributionValidationController;
use App\Modules\Contributions\Http\Controllers\Api\ContributorProfileController;
use App\Modules\Contributions\Http\Controllers\Api\LyricOptInController;
use App\Modules\Contributions\Support\ContributionsModule;
use Illuminate\Support\Facades\Route;

/*
| Contributions module API routes. Mounted under /api/contributions by the
| module provider, behind auth:sanctum. Routes are always registered; the
| contributor-facing group is gated by the runtime `contributions.enabled`
| toggle. The admin group stays reachable so operators can manage and flip the
| toggle even while the feature is switched off for users.
*/

// Public availability — lets the web decide whether to surface the nav links
// and Edula cards. No auth, no toggle gate.
Route::get('/status', fn () => response()->json([
    'success' => true,
    'data' => [
        'enabled' => ContributionsModule::enabled(),
        'feed_cards_enabled' => ContributionsModule::feedCardsEnabled(),
    ],
]))->name('status');

// Admin operator console — reachable regardless of the toggle (role-gated).
Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/settings', [ContributionAdminController::class, 'settings'])->name('settings.show');
    Route::put('/settings', [ContributionAdminController::class, 'updateSettings'])->name('settings.update');
    Route::get('/overview', [ContributionAdminController::class, 'overview'])->name('overview');
    Route::get('/tasks', [ContributionAdminController::class, 'tasks'])->name('tasks.index');
    Route::post('/tasks/import', [ContributionAdminController::class, 'importTasks'])->name('tasks.import');
    Route::post('/tasks/{task}/close', [ContributionAdminController::class, 'closeTask'])->name('tasks.close');
    Route::post('/gold', [ContributionAdminController::class, 'seedGold'])->name('gold');
    Route::post('/export', [ContributionAdminController::class, 'export'])->name('export');
});

// Contributor-facing surface — gated by the runtime on/off toggle.
Route::middleware(['auth:sanctum', 'contributions.enabled'])->group(function () {
    // Data-terms consent (9.1)
    Route::get('/consent', [ContributionConsentController::class, 'show'])->name('consent.show');
    Route::post('/consent', [ContributionConsentController::class, 'store'])->name('consent.store');

    // Contributor standing + earnings (9.4)
    Route::get('/profile', [ContributorProfileController::class, 'show'])->name('profile.show');

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
