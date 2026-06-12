<?php

use Illuminate\Support\Facades\Route;

// Public Events API Routes (no auth required)
Route::prefix('events')->name('api.events.')->group(function () {
    Route::get('/', [\App\Modules\Events\Http\Controllers\PublicEventsController::class, 'index'])->name('index');
    Route::get('/featured', [\App\Modules\Events\Http\Controllers\PublicEventsController::class, 'featured'])->name('featured');
    Route::get('/upcoming', [\App\Modules\Events\Http\Controllers\PublicEventsController::class, 'upcoming'])->name('upcoming');
    Route::get('/categories', [\App\Modules\Events\Http\Controllers\PublicEventsController::class, 'categories'])->name('categories');
    Route::get('/{id}', [\App\Modules\Events\Http\Controllers\PublicEventsController::class, 'show'])->name('show');
    Route::post('/{id}/funnel-touch', [\App\Modules\Events\Http\Controllers\PublicEventsController::class, 'trackFunnelTouch'])->name('funnel-touch');
});

Route::middleware('auth:sanctum')->post('/events/{id}/waitlist', [\App\Modules\Events\Http\Controllers\PublicEventsController::class, 'joinWaitlist'])
    ->name('api.events.waitlist');

// Ticket checkout API Routes
Route::prefix('tickets')->name('api.tickets.')->middleware('throttle:api')->group(function () {
    Route::post('/quote', [\App\Modules\Events\Http\Controllers\TicketController::class, 'quote'])->name('quote');
    Route::post('/discounts/validate', [\App\Modules\Events\Http\Controllers\TicketController::class, 'validateDiscountCode'])->name('discounts.validate');
    Route::post('/purchase', [\App\Modules\Events\Http\Controllers\TicketController::class, 'purchase'])->name('purchase');
});

// Ticket account and operations API Routes (auth required)
Route::middleware('auth:sanctum')->prefix('tickets')->name('api.tickets.account.')->group(function () {
    Route::get('/attendee-profiles', [\App\Modules\Events\Http\Controllers\TicketController::class, 'attendeeProfiles'])->name('attendee-profiles');
    Route::get('/my', [\App\Modules\Events\Http\Controllers\TicketController::class, 'myTickets'])->name('my');
    Route::post('/{id}/resend', [\App\Modules\Events\Http\Controllers\TicketController::class, 'resend'])->name('resend');
    Route::post('/{id}/transfer', [\App\Modules\Events\Http\Controllers\TicketController::class, 'transfer'])->name('transfer');
    Route::get('/{id}/cases', [\App\Modules\Events\Http\Controllers\TicketController::class, 'cases'])->name('cases.index');
    Route::post('/{id}/cases', [\App\Modules\Events\Http\Controllers\TicketController::class, 'requestCase'])->name('cases.store');
    Route::get('/validate/{ticketNumber}', [\App\Modules\Events\Http\Controllers\TicketController::class, 'validateTicket'])->name('validate');
    Route::post('/check-in', [\App\Modules\Events\Http\Controllers\TicketController::class, 'checkIn'])->name('check-in');
    Route::get('/{id}', [\App\Modules\Events\Http\Controllers\TicketController::class, 'show'])->name('show');
});

// Artist Events API Routes (auth + artist role required)
Route::middleware(['auth:sanctum', 'artist.events.access'])->prefix('artist/events')->name('api.artist.events.')->group(function () {
    Route::get('/', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'index'])->name('index');
    Route::post('/', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'store'])->name('store');
    Route::get('/{id}', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'show'])->name('show');
    Route::put('/{id}', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'update'])->name('update');
    Route::post('/{id}', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'update'])->name('update.post');
    Route::delete('/{id}', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'destroy'])->name('destroy');
    Route::get('/{id}/analytics', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'analytics'])->name('analytics');
    Route::get('/{id}/analytics/export', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'exportAnalytics'])->name('analytics.export');
    Route::post('/{id}/promotion-requests', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'storePromotionRequest'])->name('promotion-requests.store');
    Route::post('/{id}/discount-codes', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'storeDiscountCode'])->name('discount-codes.store');
    Route::delete('/{id}/discount-codes/{discountId}', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'deleteDiscountCode'])->name('discount-codes.destroy');
    Route::post('/{id}/staff', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'addStaff'])->name('staff.store');
    Route::delete('/{id}/staff/{staffId}', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'removeStaff'])->name('staff.destroy');
});

// Artist Events operations routes (check-in, offline sales, external allocations)
Route::middleware(['auth:sanctum', 'event.ops.role'])->prefix('artist/events')->name('api.artist.events.ops.')->group(function () {
    Route::get('/{id}/check-in/lookup', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'checkInLookup'])->name('checkin.lookup');
    Route::post('/{id}/check-in', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'checkInAttendee'])->name('checkin.store');
    Route::get('/{id}/ticket-cases', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'ticketCases'])->name('ticket-cases.index');
    Route::post('/{id}/ticket-cases/{caseId}/resolve', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'resolveTicketCase'])->name('ticket-cases.resolve');
    Route::get('/{id}/offline-sales', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'offlineSales'])->name('offline-sales.index');
    Route::post('/{id}/offline-sales', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'storeOfflineSale'])->name('offline-sales.store');
    Route::post('/{id}/printed-ticket-imports', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'storePrintedTicketImport'])->name('printed-ticket-imports.store');
    Route::post('/{id}/printed-ticket-imports/{orderId}/sync', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'syncPrintedTicketImport'])->name('printed-ticket-imports.sync');
    Route::post('/{id}/offline-sales/{orderId}/void', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'voidOfflineSale'])->name('offline-sales.void');
    Route::get('/{id}/external-allocations', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'externalAllocations'])->name('external-allocations.index');
    Route::post('/{id}/external-allocations', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'storeExternalAllocation'])->name('external-allocations.store');
    Route::post('/{id}/external-allocations/{allocationId}/release', [\App\Modules\Events\Http\Controllers\ArtistEventsController::class, 'releaseExternalAllocation'])->name('external-allocations.release');
});
