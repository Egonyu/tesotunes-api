<?php

use App\Http\Controllers\Api\Wallet\WalletPinController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Wallet transaction PIN
|--------------------------------------------------------------------------
|
|   GET    /api/wallet/pin/status  — has_pin / locked / session state
|   POST   /api/wallet/pin         — set the initial PIN
|   PUT    /api/wallet/pin         — change the PIN (needs the current one)
|   POST   /api/wallet/pin/verify  — unlock the money-movement window
|   POST   /api/wallet/pin/lock    — end the window early
|
| Write routes are throttled on top of the per-user lockout in WalletPinService:
| a short PIN must never be brute-forceable.
*/

Route::middleware('auth:sanctum')->prefix('wallet/pin')->name('api.wallet.pin.')->group(function () {
    Route::get('/status', [WalletPinController::class, 'status'])->name('status');
    Route::post('/lock', [WalletPinController::class, 'lock'])->name('lock');

    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/', [WalletPinController::class, 'store'])->name('store');
        Route::put('/', [WalletPinController::class, 'update'])->name('update');
        Route::post('/verify', [WalletPinController::class, 'verify'])->name('verify');
    });
});
