<?php

use App\Modules\Store\Http\Controllers\Api\DealController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Edula Partners — deals
|--------------------------------------------------------------------------
| Public browsing, member claims, merchant redemption. Loaded regardless of
| the Store module toggle: deals are Edula content, and the store record a
| partner needs is created through the seller flow.
*/

Route::prefix('deals')->name('api.deals.')->group(function () {
    Route::get('/', [DealController::class, 'index'])->name('index');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/mine', [DealController::class, 'myClaims'])->name('mine');
        Route::post('/redeem', [DealController::class, 'redeem'])->middleware('throttle:60,1')->name('redeem');
        Route::post('/{deal}/claim', [DealController::class, 'claim'])->middleware('throttle:30,1')->name('claim');
    });

    Route::get('/{deal}', [DealController::class, 'show'])->name('show');
});

Route::middleware('auth:sanctum')->prefix('merchant')->name('api.merchant.')->group(function () {
    Route::get('/stores', [DealController::class, 'myStores'])->name('stores.index');
    Route::get('/stores/{store}/deals', [DealController::class, 'storeDeals'])->name('stores.deals.index');
    Route::post('/stores/{store}/deals', [DealController::class, 'storeDeal'])->name('stores.deals.store');
    Route::post('/deals/{deal}/publish', [DealController::class, 'publish'])->name('deals.publish');
});
