<?php

use Illuminate\Support\Facades\Route;

// Store Module Promotions — public browsing, authenticated seller/buyer actions
Route::prefix('promotions')->name('promotions.')->group(function () {
    Route::get('/', [\App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'index'])->name('index');
    Route::get('/platforms/list', [\App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'platforms'])->name('platforms');
    Route::get('/{slug}', [\App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'show'])->name('show');

    // Seller actions — promoter capability required (admins always pass)
    Route::middleware(['auth:sanctum', 'capability:promoter'])->group(function () {
        Route::post('/', [\App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'store'])->name('store');
        Route::put('/{product}', [\App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'update'])->name('update');
        Route::delete('/{product}', [\App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'destroy'])->name('destroy');
        Route::post('/{product}/pause', [\App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'pause'])->name('pause');
        Route::post('/{product}/activate', [\App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'activate'])->name('activate');
    });

    // Buyer actions — any authenticated user
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/{slug}/purchase', [\App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'purchase'])->name('purchase');
        Route::post('/orders/{orderId}/submit-verification', [\App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'submitVerification'])->name('orders.submit-verification');
        Route::post('/orders/{orderId}/dispute', [\App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'dispute'])->name('orders.dispute');
        Route::post('/orders/{orderId}/review', [\App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'review'])->name('orders.review');
    });

    // Seller order actions — promoter capability required (admins always pass)
    Route::middleware(['auth:sanctum', 'capability:promoter'])->group(function () {
        Route::post('/orders/{orderId}/verify', [\App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'verifyCompletionById'])->name('orders.verify');
        Route::post('/orders/{orderId}/reject', [\App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'rejectCompletionById'])->name('orders.reject');
    });
});

Route::get('/promoters/{username}', [\App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'promoterProfile'])->name('store.promoters.profile');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/my/promotions', [\App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'myPromotions'])->name('my.promotions');
    Route::get('/my/promotions/purchases', [\App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'myPurchases'])->name('my.promotions.purchases');
    Route::get('/my/promotions/purchases/{orderId}', [\App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'myPurchase'])->name('my.promotions.purchase');
    Route::get('/my/promotions/orders', [\App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'sellerOrders'])->name('my.promotions.orders');
    Route::get('/my/promotions/orders/{orderId}', [\App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'showOrder'])->name('my.promotions.order');
    Route::get('/my/promotions/analytics', [\App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'statistics'])->name('my.promotions.analytics');
    Route::get('/my/promotions/{promotionId}', [\App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'show'])->name('my.promotions.show');
    Route::get('/my/promoter-profile', [\App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'profile'])->name('my.promoter-profile');
    Route::put('/my/promoter-profile', [\App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'updateProfile'])->name('my.promoter-profile.update');
});

// Admin promotion moderation — secured with auth + role middleware (SEC-CRIT fix)
Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])->prefix('admin/promotions')->name('admin.promotions.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\AdminPromotionsController::class, 'index'])->name('index');
    Route::post('/{promotion}/approve', [\App\Http\Controllers\Api\Admin\AdminPromotionsController::class, 'approve'])->name('approve');
    Route::post('/{promotion}/reject', [\App\Http\Controllers\Api\Admin\AdminPromotionsController::class, 'reject'])->name('reject');
    Route::get('/disputes', [\App\Http\Controllers\Api\Admin\AdminPromotionsController::class, 'disputes'])->name('disputes');
    Route::post('/disputes/{disputeId}/resolve', [\App\Http\Controllers\Api\Admin\AdminPromotionsController::class, 'resolveDispute'])->name('disputes.resolve');
    Route::get('/analytics', [\App\Http\Controllers\Api\Admin\AdminPromotionsController::class, 'analytics'])->name('analytics');
});

// Admin Store API — SECURED with auth + role middleware (SEC-CRIT-2 fix)
Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])->prefix('admin/store')->name('admin.store.api.')->group(function () {
    Route::get('/stats', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'stats'])->name('stats');
    Route::get('/products', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'products'])->name('products.index');
    Route::post('/products', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'createProduct'])->name('products.store');
    Route::put('/products/{product}', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'updateProduct'])->name('products.update');
    Route::post('/products/{product}/toggle-status', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'toggleProductStatus'])->name('products.toggle');
    Route::delete('/products/{product}', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'deleteProduct'])->name('products.delete');
    Route::get('/orders', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'orders'])->name('orders.index');
    Route::post('/orders/{order}/status', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'updateOrderStatus'])->name('orders.status');
    Route::get('/shops', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'shops'])->name('shops.index');
    Route::post('/shops', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'createShop'])->name('shops.store');
    Route::put('/shops/{store}', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'updateShop'])->name('shops.update');
    Route::post('/shops/{store}/toggle-status', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'toggleShopStatus'])->name('shops.toggle');
    Route::post('/shops/{store}/approve', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'approveShop'])->name('shops.approve');
    Route::post('/shops/{store}/suspend', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'suspendShop'])->name('shops.suspend');
    Route::post('/shops/{store}/verify', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'verifyShop'])->name('shops.verify');
    Route::post('/shops/{store}/unverify', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'unverifyShop'])->name('shops.unverify');
    Route::delete('/shops/{store}', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'deleteShop'])->name('shops.delete');
    Route::get('/analytics', [\App\Http\Controllers\Api\Admin\StoreApiController::class, 'analytics'])->name('analytics');
});

Route::middleware(['auth:sanctum', 'role:admin,super_admin', 'admin.exceptions'])->prefix('admin/featured')->name('admin.featured.api.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\FeaturedContentController::class, 'index'])->name('index');
    Route::post('/', [\App\Http\Controllers\Api\Admin\FeaturedContentController::class, 'store'])->name('store');
    Route::post('/reorder', [\App\Http\Controllers\Api\Admin\FeaturedContentController::class, 'reorder'])->name('reorder');
    Route::post('/{id}/toggle', [\App\Http\Controllers\Api\Admin\FeaturedContentController::class, 'toggle'])->name('toggle');
    Route::put('/{id}', [\App\Http\Controllers\Api\Admin\FeaturedContentController::class, 'update'])->name('update');
    Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\FeaturedContentController::class, 'destroy'])->name('destroy');
});
