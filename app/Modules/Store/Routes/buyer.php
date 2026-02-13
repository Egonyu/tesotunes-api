<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Store Buyer Routes
|--------------------------------------------------------------------------
|
| Routes for customers to browse and purchase from stores
| Public access with store.enabled middleware
|
*/

// Placeholder - will implement controllers in next phase
Route::get('/', function () {
    return response()->json([
        'message' => 'Store module is active',
        'module' => 'store',
        'version' => '1.0.0'
    ]);
})->name('index');
