<?php

use App\Modules\Store\Http\Controllers\Api\PromotionController;
use App\Modules\Store\Http\Controllers\Api\SellerPromotionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Store Promotion Routes — single definition
|--------------------------------------------------------------------------
|
| Required by both app/Modules/Store/Routes/api.php (production, mounted by
| the module provider) and routes/api/store.php (loaded only during test
| runs). Both callers mount this under the `store` prefix.
|
| These groups used to be declared twice, once in each of those files. The
| copies drifted: the capability gate below existed only in the test-time
| copy, so three security tests asserted a 403 that production never
| returned, while the production route was reachable by any authenticated
| account. One definition removes the class of bug, not just the instance.
|
*/

// Read-only promotion views. Order actions (accept, dispute, deliver) live
// only in routes/api/promotions.php — the copies here passed an order-item id
// where an order id was expected, and one let the promoter release their own
// payment.
Route::middleware('auth:sanctum')->prefix('promotions')->name('promotions.')->group(function () {
    Route::get('/', [PromotionController::class, 'index'])->name('index');
    Route::get('/my-promotions', [PromotionController::class, 'myPromotions'])->name('my');
    Route::get('/{slug}', [PromotionController::class, 'show'])->name('show');
});

// Seller actions — sellers (any shop owner, not just artists) plus artists,
// who had access before the capability gate. Admins always pass.
Route::middleware(['auth:sanctum', 'capability:seller,artist'])
    ->prefix('seller/promotions')
    ->name('seller.promotions.')
    ->group(function () {
        Route::get('/', [SellerPromotionController::class, 'index'])->name('index');
        Route::post('/', [SellerPromotionController::class, 'store'])->name('store');
        Route::put('/{product:id}', [SellerPromotionController::class, 'update'])->name('update');
        Route::delete('/{product:id}', [SellerPromotionController::class, 'destroy'])->name('destroy');
        Route::get('/pending-verifications', [SellerPromotionController::class, 'pendingVerifications'])->name('pending-verifications');
        Route::get('/statistics', [SellerPromotionController::class, 'statistics'])->name('statistics');
    });
