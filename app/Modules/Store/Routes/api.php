<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Store\Http\Controllers\Api\{
    StoreController,
    ProductController,
    CartController,
    OrderController,
    PaymentController,
    NotificationController,
    ReviewController,
    AnalyticsController,
    ReportController,
    PromotionController,
    SellerPromotionController
};

/*
|--------------------------------------------------------------------------
| Store API Routes (v1)
|--------------------------------------------------------------------------
|
| RESTful API endpoints for the store module
| Prefix: /api/v1/store
| 
| Authentication: Most routes require auth:sanctum middleware
| Rate Limiting: Applied per route group
|
*/

// Health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'module' => 'store',
        'enabled' => config('store.enabled', false),
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String()
    ]);
})->name('health');

/*
|--------------------------------------------------------------------------
| Public Store & Product Routes
|--------------------------------------------------------------------------
| No authentication required - Public browsing
*/
Route::prefix('public')->name('public.')->group(function () {
    // Stores
    Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
    Route::get('/stores/featured', [StoreController::class, 'featured'])->name('stores.featured');
    Route::get('/stores/{identifier}', [StoreController::class, 'show'])->name('stores.show');
    
    // Products
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/featured', [ProductController::class, 'featured'])->name('products.featured');
    Route::get('/products/trending', [ProductController::class, 'trending'])->name('products.trending');
    Route::get('/products/{identifier}', [ProductController::class, 'show'])->name('products.show');
    Route::get('/products/{product}/availability', [ProductController::class, 'checkAvailability'])->name('products.availability');
    
    // Products by store
    Route::get('/stores/{store}/products', [ProductController::class, 'byStore'])->name('stores.products');
    
    // Public reviews
    Route::get('/products/{product}/reviews', [ReviewController::class, 'productReviews'])->name('products.reviews');
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes - Buyers
|--------------------------------------------------------------------------
| Require authentication for shopping actions
*/
Route::middleware(['auth:sanctum'])->group(function () {
    
    // Shopping Cart
    Route::prefix('cart')->name('cart.')->group(function () {
        Route::get('/', [CartController::class, 'index'])->name('index');
        Route::post('/items', [CartController::class, 'addItem'])->name('items.add');
        Route::put('/items/{itemId}', [CartController::class, 'updateItem'])->name('items.update');
        Route::delete('/items/{itemId}', [CartController::class, 'removeItem'])->name('items.remove');
        Route::delete('/', [CartController::class, 'clear'])->name('clear');
        Route::post('/validate', [CartController::class, 'validate'])->name('validate');
    });
    
    // Orders - Buyer View
    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::post('/', [OrderController::class, 'store'])->name('store');
        Route::get('/{orderNumber}', [OrderController::class, 'show'])->name('show');
        Route::post('/{orderNumber}/cancel', [OrderController::class, 'cancel'])->name('cancel');
        Route::get('/{orderNumber}/tracking', [OrderController::class, 'tracking'])->name('tracking');
    });
    
    // Payments
    Route::prefix('payments')->name('payments.')->group(function () {
        Route::get('/methods', [PaymentController::class, 'methods'])->name('methods');
        Route::post('/{orderNumber}/initiate', [PaymentController::class, 'initiate'])->name('initiate');
        Route::get('/{orderNumber}/status', [PaymentController::class, 'status'])->name('status');
    });
    
    // Notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead'])->name('mark-read');
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead'])->name('read-all');
        Route::delete('/{id}', [NotificationController::class, 'destroy'])->name('destroy');
        Route::get('/preferences', [NotificationController::class, 'getPreferences'])->name('preferences');
        Route::put('/preferences', [NotificationController::class, 'updatePreferences'])->name('preferences.update');
    });
    
    // Reviews
    Route::prefix('reviews')->name('reviews.')->group(function () {
        Route::post('/products/{product}', [ReviewController::class, 'createProductReview'])->name('products.create');
        Route::get('/products/{product}/can-review', [ReviewController::class, 'canReview'])->name('products.can-review');
        Route::put('/{review}', [ReviewController::class, 'update'])->name('update');
        Route::delete('/{review}', [ReviewController::class, 'destroy'])->name('destroy');
        Route::post('/{review}/helpful', [ReviewController::class, 'markHelpful'])->name('helpful');
    });
    
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes - Store Owners/Sellers
|--------------------------------------------------------------------------
| Require authentication and store ownership verification
*/
Route::middleware(['auth:sanctum'])->prefix('seller')->name('seller.')->group(function () {
    
    // Store Management
    Route::prefix('stores')->name('stores.')->group(function () {
        Route::post('/', [StoreController::class, 'store'])->name('store');
        Route::get('/{store}', [StoreController::class, 'show'])->name('show');
        Route::put('/{store}', [StoreController::class, 'update'])->name('update');
        Route::get('/{store}/statistics', [StoreController::class, 'statistics'])->name('statistics');
        Route::post('/{store}/activate', [StoreController::class, 'activate'])->name('activate');
    });
    
    // Seller Orders
    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'sellerOrders'])->name('index');
        Route::put('/{orderNumber}/status', [OrderController::class, 'updateStatus'])->name('update-status');
    });
    
    // Seller Reviews
    Route::prefix('reviews')->name('reviews.')->group(function () {
        Route::post('/{review}/respond', [ReviewController::class, 'addSellerResponse'])->name('respond');
    });
    
    // Analytics
    Route::prefix('analytics')->name('analytics.')->group(function () {
        Route::get('/{store}/dashboard', [AnalyticsController::class, 'dashboard'])->name('dashboard');
        Route::get('/{store}/realtime', [AnalyticsController::class, 'realtime'])->name('realtime');
        Route::get('/{store}/export', [AnalyticsController::class, 'export'])->name('export');
    });
    
    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::post('/{store}/generate', [ReportController::class, 'generate'])->name('generate');
        Route::get('/{store}/download/{filename}', [ReportController::class, 'download'])->name('download');
    });
    
    // Promotions (Seller)
    Route::prefix('promotions')->name('promotions.')->group(function () {
        Route::get('/', [SellerPromotionController::class, 'index'])->name('index');
        Route::post('/', [SellerPromotionController::class, 'store'])->name('store');
        Route::put('/{product:id}', [SellerPromotionController::class, 'update'])->name('update');
        Route::delete('/{product:id}', [SellerPromotionController::class, 'destroy'])->name('destroy');
        Route::get('/pending-verifications', [SellerPromotionController::class, 'pendingVerifications'])->name('pending-verifications');
        Route::post('/order-items/{orderItem}/verify', [SellerPromotionController::class, 'verifyCompletion'])->name('verify');
        Route::get('/statistics', [SellerPromotionController::class, 'statistics'])->name('statistics');
    });
    
});

/*
|--------------------------------------------------------------------------
| Buyer/User Routes  
|--------------------------------------------------------------------------
| Require authentication for buyers
*/
Route::middleware(['auth:sanctum'])->group(function () {
    
    // Promotions (Buyer)
    Route::prefix('promotions')->name('promotions.')->group(function () {
        Route::get('/', [PromotionController::class, 'index'])->name('index');
        Route::get('/my-promotions', [PromotionController::class, 'myPromotions'])->name('my');
        Route::get('/{slug}', [PromotionController::class, 'show'])->name('show');
        Route::post('/order-items/{orderItem}/submit-verification', [PromotionController::class, 'submitVerification'])->name('submit-verification');
        Route::post('/order-items/{orderItem}/dispute', [PromotionController::class, 'dispute'])->name('dispute');
    });
    
});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
| Require admin authentication
*/
Route::middleware(['auth:sanctum', 'role:admin|super_admin'])->prefix('admin')->name('admin.')->group(function () {
    
    // Store Administration
    Route::prefix('stores')->name('stores.')->group(function () {
        Route::post('/{store}/suspend', [StoreController::class, 'suspend'])->name('suspend');
    });
    
});

/*
|--------------------------------------------------------------------------
| Payment Webhooks
|--------------------------------------------------------------------------
| No authentication - Validated by signature
*/
Route::post('/webhooks/payment', [PaymentController::class, 'webhook'])->name('webhooks.payment');
