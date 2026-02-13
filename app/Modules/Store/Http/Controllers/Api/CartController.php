<?php

namespace App\Modules\Store\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Store\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Shopping Cart API Controller
 */
class CartController extends Controller
{
    /**
     * Get current user's cart.
     */
    public function index(Request $request): JsonResponse
    {
        $cart = $request->user()->cart()->with([
            'items.product.store',
            'items.product.category',
        ])->first();

        if (!$cart) {
            return response()->json([
                'data' => [
                    'items' => [],
                    'total_ugx' => 0,
                    'total_credits' => 0,
                    'items_count' => 0,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'items' => $cart->items,
                'total_ugx' => $cart->total_ugx,
                'total_credits' => $cart->total_credits,
                'items_count' => $cart->items->count(),
            ],
        ]);
    }

    /**
     * Add item to cart.
     */
    public function addItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:store_products,id',
            'quantity' => 'required|integer|min:1',
            'payment_method' => 'nullable|in:ugx,credits,hybrid',
            'hybrid_ugx' => 'nullable|numeric|min:0',
            'hybrid_credits' => 'nullable|integer|min:0',
        ]);

        $product = Product::findOrFail($validated['product_id']);

        if ($product->track_inventory && $product->inventory_quantity < $validated['quantity']) {
            return response()->json([
                'message' => 'Insufficient inventory.',
            ], 422);
        }

        $cart = $request->user()->cart()->firstOrCreate([
            'user_id' => $request->user()->id,
        ]);

        $existingItem = $cart->items()->where('product_id', $product->id)->first();

        if ($existingItem) {
            $newQuantity = $existingItem->quantity + $validated['quantity'];
            $existingItem->update(['quantity' => $newQuantity]);
        } else {
            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => $validated['quantity'],
                'price_ugx' => $product->price_ugx,
                'price_credits' => $product->price_credits,
                'payment_method' => $validated['payment_method'] ?? 'ugx',
                'hybrid_ugx' => $validated['hybrid_ugx'] ?? null,
                'hybrid_credits' => $validated['hybrid_credits'] ?? null,
            ]);
        }

        $cart->updateTotals();

        return response()->json([
            'data' => $cart->load('items.product'),
            'message' => 'Item added to cart.',
        ]);
    }

    /**
     * Update cart item quantity.
     */
    public function updateItem(Request $request, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cart = $request->user()->cart()->firstOrFail();
        $item = $cart->items()->findOrFail($itemId);

        if ($item->product->track_inventory &&
            $item->product->inventory_quantity < $validated['quantity']) {
            return response()->json([
                'message' => 'Insufficient inventory.',
            ], 422);
        }

        $item->update(['quantity' => $validated['quantity']]);
        $cart->updateTotals();

        return response()->json([
            'data' => $cart->load('items.product'),
            'message' => 'Cart updated.',
        ]);
    }

    /**
     * Remove item from cart.
     */
    public function removeItem(Request $request, int $itemId): JsonResponse
    {
        $cart = $request->user()->cart()->firstOrFail();
        $item = $cart->items()->findOrFail($itemId);
        $item->delete();

        $cart->updateTotals();

        return response()->json([
            'message' => 'Item removed from cart.',
        ]);
    }

    /**
     * Clear entire cart.
     */
    public function clear(Request $request): JsonResponse
    {
        $cart = $request->user()->cart()->first();

        if ($cart) {
            $cart->items()->delete();
            $cart->updateTotals();
        }

        return response()->json([
            'message' => 'Cart cleared.',
        ]);
    }

    /**
     * Validate cart before checkout.
     */
    public function validateCart(Request $request): JsonResponse
    {
        $cart = $request->user()->cart()->with('items.product')->firstOrFail();

        $errors = [];

        foreach ($cart->items as $item) {
            if ($item->product->status !== Product::STATUS_ACTIVE) {
                $errors[] = "Product '{$item->product->name}' is no longer available.";
            }

            if ($item->product->track_inventory &&
                $item->product->inventory_quantity < $item->quantity) {
                $errors[] = "Insufficient stock for '{$item->product->name}'.";
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'message' => 'Cart validation failed.',
                'errors' => $errors,
            ], 422);
        }

        return response()->json([
            'data' => [
                'total_ugx' => $cart->total_ugx,
                'total_credits' => $cart->total_credits,
                'items_count' => $cart->items->count(),
            ],
            'message' => 'Cart is valid.',
        ]);
    }
}
