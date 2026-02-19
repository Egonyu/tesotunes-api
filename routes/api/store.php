<?php

use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Store API Routes
|--------------------------------------------------------------------------
|
| API endpoints for store module AJAX functionality.
| Used for cart operations, product filtering, and real-time updates.
|
*/

Route::middleware(['auth'])->prefix('store')->name('store.')->group(function () {

    // Promotion endpoints (Buyer)
    Route::prefix('promotions')->name('promotions.')->group(function () {
        Route::get('/', [App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'index'])->name('index');
        Route::get('/my-promotions', [App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'myPromotions'])->name('my');
        Route::get('/{slug}', [App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'show'])->name('show');
        Route::post('/order-items/{orderItem}/submit-verification', [App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'submitVerification'])->name('submit-verification');
        Route::post('/order-items/{orderItem}/dispute', [App\Modules\Store\Http\Controllers\Api\PromotionController::class, 'dispute'])->name('dispute');
    });

    // Seller Promotion endpoints (Artist)
    Route::prefix('seller/promotions')->name('seller.promotions.')->group(function () {
        Route::get('/', [App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'index'])->name('index');
        Route::post('/', [App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'store'])->name('store');
        Route::put('/{product}', [App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'update'])->name('update');
        Route::delete('/{product}', [App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'destroy'])->name('destroy');
        Route::get('/pending-verifications', [App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'pendingVerifications'])->name('pending-verifications');
        Route::post('/order-items/{orderItem}/verify', [App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'verifyCompletion'])->name('verify');
        Route::get('/statistics', [App\Modules\Store\Http\Controllers\Api\SellerPromotionController::class, 'statistics'])->name('statistics');
    });

    // Cart API endpoints
    Route::prefix('cart')->name('cart.')->group(function () {
        Route::get('/', function (Request $request, CartService $cartService) {
            $items = $cartService->getCartWithProducts();

            return response()->json([
                'data' => [
                    'items' => $items->map(function ($item) {
                        return [
                            'id' => $item['id'],
                            'product' => [
                                'id' => $item['product']->id,
                                'name' => $item['product']->name,
                                'slug' => $item['product']->slug,
                                'image_url' => $item['product']->featured_image_url,
                                'category' => $item['product']->category,
                                'shop_id' => $item['product']->store_id,
                                'shop' => [
                                    'name' => $item['product']->store->name ?? 'Unknown Store',
                                ],
                                'stock_quantity' => $item['product']->inventory_quantity,
                            ],
                            'quantity' => $item['quantity'],
                            'price_ugx' => $item['price'],
                            'price_credits' => $item['product']->price_credits ?? 0,
                        ];
                    })->values()->all(),
                    'total' => $cartService->getTotal(),
                    'items_count' => $cartService->getItemCount(),
                ],
            ]);
        })->name('get');

        Route::post('/add', function (Request $request, CartService $cartService) {
            $validated = $request->validate([
                'product_id' => 'required|exists:store_products,id',
                'quantity' => 'required|integer|min:1',
                'payment_method' => 'nullable|in:money,credit',
            ]);

            $product = Product::findOrFail($validated['product_id']);

            // Check inventory if tracking is enabled
            if ($product->track_inventory && $product->inventory_quantity < $validated['quantity']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient stock. Only '.$product->inventory_quantity.' items available.',
                ], 422);
            }

            try {
                $cartService->addItem(
                    $product,
                    $validated['quantity'],
                    ['payment_method' => $validated['payment_method'] ?? 'money']
                );

                $items = $cartService->getCartWithProducts();

                return response()->json([
                    'success' => true,
                    'cart_count' => $cartService->getItemCount(),
                    'items' => $items->map(function ($item) {
                        return [
                            'id' => $item['id'],
                            'product' => [
                                'id' => $item['product']->id,
                                'name' => $item['product']->name,
                                'slug' => $item['product']->slug,
                                'image_url' => $item['product']->featured_image_url,
                            ],
                            'quantity' => $item['quantity'],
                            'price_ugx' => $item['price'],
                        ];
                    }),
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        })->name('add');

        Route::put('/update', function (Request $request, CartService $cartService) {
            $validated = $request->validate([
                'item_id' => 'required|string',
                'quantity' => 'required|integer|min:0',
            ]);

            $cartService->updateQuantity($validated['item_id'], $validated['quantity']);

            $items = $cartService->getCartWithProducts();

            return response()->json([
                'success' => true,
                'items' => $items->map(function ($item) {
                    return [
                        'id' => $item['id'],
                        'product' => [
                            'id' => $item['product']->id,
                            'name' => $item['product']->name,
                            'slug' => $item['product']->slug,
                            'image_url' => $item['product']->featured_image_url,
                            'shop' => [
                                'name' => $item['product']->store->name ?? 'Unknown Store',
                            ],
                            'stock_quantity' => $item['product']->inventory_quantity,
                        ],
                        'quantity' => $item['quantity'],
                        'price_ugx' => $item['price'],
                    ];
                }),
            ]);
        })->name('update');

        Route::delete('/remove', function (Request $request, CartService $cartService) {
            $validated = $request->validate([
                'item_id' => 'required|string',
            ]);

            $cartService->removeItem($validated['item_id']);

            return response()->json([
                'success' => true,
                'count' => $cartService->getItemCount(),
            ]);
        })->name('remove');

        Route::delete('/clear', function (Request $request, CartService $cartService) {
            $cartService->clear();

            return response()->json(['success' => true]);
        })->name('clear');

        // RESTful item routes
        Route::put('/items/{itemId}', function (Request $request, CartService $cartService, string $itemId) {
            $validated = $request->validate([
                'quantity' => 'required|integer|min:1',
            ]);

            $cartService->updateQuantity($itemId, $validated['quantity']);

            return response()->json([
                'success' => true,
                'cart_count' => $cartService->getItemCount(),
            ]);
        })->name('items.update');

        Route::delete('/items/{itemId}', function (Request $request, CartService $cartService, string $itemId) {
            $cartService->removeItem($itemId);

            return response()->json([
                'success' => true,
                'count' => $cartService->getItemCount(),
            ]);
        })->name('items.destroy');

        // Also support DELETE on entire cart
        Route::delete('/', function (Request $request, CartService $cartService) {
            $cartService->clear();

            return response()->json(['success' => true]);
        })->name('destroy');
    });

    // Order API endpoints
    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [\App\Modules\Store\Http\Controllers\Api\OrderController::class, 'index'])->name('index');
        Route::post('/', [\App\Modules\Store\Http\Controllers\Api\OrderController::class, 'store'])->name('store');
        Route::get('/{orderNumber}', [\App\Modules\Store\Http\Controllers\Api\OrderController::class, 'show'])->name('show');
        Route::post('/{orderNumber}/cancel', [\App\Modules\Store\Http\Controllers\Api\OrderController::class, 'cancel'])->name('cancel');
    });

    // Product search/filter API
    Route::get('/products/search', function (Request $request) {
        $products = Product::with('store.owner')
            ->where('status', 'active')
            ->when($request->q, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->type, function ($query, $type) {
                $query->where('type', $type);
            })
            ->when($request->min_price, function ($query, $min) {
                $query->where('price', '>=', $min * 100);
            })
            ->when($request->max_price, function ($query, $max) {
                $query->where('price', '<=', $max * 100);
            })
            ->limit(20)
            ->get();

        return response()->json($products);
    })->name('products.search');

    // Store search API
    Route::get('/stores/search', function (Request $request) {
        $stores = Store::with('owner')
            ->where('status', 'active')
            ->when($request->q, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->limit(10)
            ->get();

        return response()->json($stores);
    })->name('stores.search');

    // Product availability check
    Route::post('/products/check-availability', function (Request $request) {
        $validated = $request->validate([
            'product_id' => 'required|exists:store_products,id',
            'quantity' => 'required|integer|min:1',
            'variant_id' => 'nullable|exists:store_product_variants,id',
        ]);

        $product = Product::findOrFail($validated['product_id']);

        $available = true;
        $message = 'Product available';

        if ($product->type === 'physical') {
            if ($product->stock_quantity < $validated['quantity']) {
                $available = false;
                $message = "Only {$product->stock_quantity} items available";
            }
        }

        return response()->json([
            'available' => $available,
            'message' => $message,
            'stock' => $product->stock_quantity,
        ]);
    })->name('products.check-availability');

});
