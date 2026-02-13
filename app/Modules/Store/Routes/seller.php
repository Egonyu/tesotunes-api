<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Store Seller Routes
|--------------------------------------------------------------------------
|
| Routes for store owners to manage their stores, products, and orders
| All routes require authentication and store.enabled middleware
|
*/

// Placeholder - will implement controllers in next phase
Route::get('/', function () {
    return view('store::seller.index');
})->name('index');
