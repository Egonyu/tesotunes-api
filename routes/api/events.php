<?php

use Illuminate\Support\Facades\Route;

// Public Events API Routes (no auth required)
Route::prefix('events')->name('api.events.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\PublicEventsController::class, 'index'])->name('index');
    Route::get('/featured', [\App\Http\Controllers\Api\PublicEventsController::class, 'featured'])->name('featured');
    Route::get('/upcoming', [\App\Http\Controllers\Api\PublicEventsController::class, 'upcoming'])->name('upcoming');
    Route::get('/categories', [\App\Http\Controllers\Api\PublicEventsController::class, 'categories'])->name('categories');
    Route::get('/{id}', [\App\Http\Controllers\Api\PublicEventsController::class, 'show'])->name('show');
    Route::post('/{id}/funnel-touch', [\App\Http\Controllers\Api\PublicEventsController::class, 'trackFunnelTouch'])->name('funnel-touch');
});

Route::middleware('auth:sanctum')->post('/events/{id}/waitlist', [\App\Http\Controllers\Api\PublicEventsController::class, 'joinWaitlist'])
    ->name('api.events.waitlist');

// Ticket checkout API Routes
Route::prefix('tickets')->name('api.tickets.')->middleware('throttle:api')->group(function () {
    Route::post('/quote', [\App\Http\Controllers\Api\TicketController::class, 'quote'])->name('quote');
    Route::post('/discounts/validate', [\App\Http\Controllers\Api\TicketController::class, 'validateDiscountCode'])->name('discounts.validate');
    Route::post('/purchase', [\App\Http\Controllers\Api\TicketController::class, 'purchase'])->name('purchase');
});

// Ticket account and operations API Routes (auth required)
Route::middleware('auth:sanctum')->prefix('tickets')->name('api.tickets.account.')->group(function () {
    Route::get('/attendee-profiles', [\App\Http\Controllers\Api\TicketController::class, 'attendeeProfiles'])->name('attendee-profiles');
    Route::get('/my', [\App\Http\Controllers\Api\TicketController::class, 'myTickets'])->name('my');
    Route::post('/{id}/resend', [\App\Http\Controllers\Api\TicketController::class, 'resend'])->name('resend');
    Route::post('/{id}/transfer', [\App\Http\Controllers\Api\TicketController::class, 'transfer'])->name('transfer');
    Route::get('/{id}/cases', [\App\Http\Controllers\Api\TicketController::class, 'cases'])->name('cases.index');
    Route::post('/{id}/cases', [\App\Http\Controllers\Api\TicketController::class, 'requestCase'])->name('cases.store');
    Route::get('/validate/{ticketNumber}', [\App\Http\Controllers\Api\TicketController::class, 'validateTicket'])->name('validate');
    Route::post('/check-in', [\App\Http\Controllers\Api\TicketController::class, 'checkIn'])->name('check-in');
    Route::get('/{id}', [\App\Http\Controllers\Api\TicketController::class, 'show'])->name('show');
});

// Artist Events API Routes (auth + artist role required)
Route::middleware(['auth:sanctum', 'artist.events.access'])->prefix('artist/events')->name('api.artist.events.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\ArtistEventsController::class, 'index'])->name('index');
    Route::post('/', [\App\Http\Controllers\Api\ArtistEventsController::class, 'store'])->name('store');
    Route::get('/{id}', [\App\Http\Controllers\Api\ArtistEventsController::class, 'show'])->name('show');
    Route::put('/{id}', [\App\Http\Controllers\Api\ArtistEventsController::class, 'update'])->name('update');
    Route::post('/{id}', [\App\Http\Controllers\Api\ArtistEventsController::class, 'update'])->name('update.post');
    Route::delete('/{id}', [\App\Http\Controllers\Api\ArtistEventsController::class, 'destroy'])->name('destroy');
    Route::get('/{id}/analytics', [\App\Http\Controllers\Api\ArtistEventsController::class, 'analytics'])->name('analytics');
    Route::get('/{id}/analytics/export', [\App\Http\Controllers\Api\ArtistEventsController::class, 'exportAnalytics'])->name('analytics.export');
    Route::post('/{id}/promotion-requests', [\App\Http\Controllers\Api\ArtistEventsController::class, 'storePromotionRequest'])->name('promotion-requests.store');
    Route::post('/{id}/discount-codes', [\App\Http\Controllers\Api\ArtistEventsController::class, 'storeDiscountCode'])->name('discount-codes.store');
    Route::delete('/{id}/discount-codes/{discountId}', [\App\Http\Controllers\Api\ArtistEventsController::class, 'deleteDiscountCode'])->name('discount-codes.destroy');
    Route::post('/{id}/staff', [\App\Http\Controllers\Api\ArtistEventsController::class, 'addStaff'])->name('staff.store');
    Route::delete('/{id}/staff/{staffId}', [\App\Http\Controllers\Api\ArtistEventsController::class, 'removeStaff'])->name('staff.destroy');
});

// Artist Events operations routes (check-in, offline sales, external allocations)
Route::middleware(['auth:sanctum', 'event.ops.role'])->prefix('artist/events')->name('api.artist.events.ops.')->group(function () {
    Route::get('/{id}/check-in/lookup', [\App\Http\Controllers\Api\ArtistEventsController::class, 'checkInLookup'])->name('checkin.lookup');
    Route::post('/{id}/check-in', [\App\Http\Controllers\Api\ArtistEventsController::class, 'checkInAttendee'])->name('checkin.store');
    Route::get('/{id}/ticket-cases', [\App\Http\Controllers\Api\ArtistEventsController::class, 'ticketCases'])->name('ticket-cases.index');
    Route::post('/{id}/ticket-cases/{caseId}/resolve', [\App\Http\Controllers\Api\ArtistEventsController::class, 'resolveTicketCase'])->name('ticket-cases.resolve');
    Route::get('/{id}/offline-sales', [\App\Http\Controllers\Api\ArtistEventsController::class, 'offlineSales'])->name('offline-sales.index');
    Route::post('/{id}/offline-sales', [\App\Http\Controllers\Api\ArtistEventsController::class, 'storeOfflineSale'])->name('offline-sales.store');
    Route::post('/{id}/printed-ticket-imports', [\App\Http\Controllers\Api\ArtistEventsController::class, 'storePrintedTicketImport'])->name('printed-ticket-imports.store');
    Route::post('/{id}/printed-ticket-imports/{orderId}/sync', [\App\Http\Controllers\Api\ArtistEventsController::class, 'syncPrintedTicketImport'])->name('printed-ticket-imports.sync');
    Route::post('/{id}/offline-sales/{orderId}/void', [\App\Http\Controllers\Api\ArtistEventsController::class, 'voidOfflineSale'])->name('offline-sales.void');
    Route::get('/{id}/external-allocations', [\App\Http\Controllers\Api\ArtistEventsController::class, 'externalAllocations'])->name('external-allocations.index');
    Route::post('/{id}/external-allocations', [\App\Http\Controllers\Api\ArtistEventsController::class, 'storeExternalAllocation'])->name('external-allocations.store');
    Route::post('/{id}/external-allocations/{allocationId}/release', [\App\Http\Controllers\Api\ArtistEventsController::class, 'releaseExternalAllocation'])->name('external-allocations.release');
});
