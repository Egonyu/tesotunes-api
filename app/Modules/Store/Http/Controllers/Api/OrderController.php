<?php

namespace App\Modules\Store\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Store\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Order API Controller
 */
class OrderController extends Controller
{
    /**
     * List user's orders.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->orders()
            ->with([
                'items.product.store',
                'store:id,name,slug',
            ])
            ->orderByDesc('created_at');

        if ($status = $request->status) {
            $query->where('status', $status);
        }

        $orders = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    /**
     * Get order details.
     */
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::where('order_number', $orderNumber)
            ->with([
                'items.product',
                'store:id,name,slug',
            ])
            ->firstOrFail();

        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        return response()->json([
            'data' => $order,
        ]);
    }

    /**
     * Create order from cart (checkout).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'sometimes|required|array|min:1',
            'items.*.product_id' => 'required_with:items|exists:store_products,id',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'payment_method' => 'required|in:mobile_money,credits,hybrid',
            'phone_number' => 'required_if:payment_method,mobile_money,hybrid|string',
            'hybrid_ugx' => 'required_if:payment_method,hybrid|numeric|min:0',
            'hybrid_credits' => 'required_if:payment_method,hybrid|integer|min:0',
            'shipping_address' => 'required|array',
            'shipping_address.full_name' => 'required|string',
            'shipping_address.phone' => 'required|string',
            'shipping_address.address_line_1' => 'required|string',
            'shipping_address.address_line_2' => 'nullable|string',
            'shipping_address.city' => 'required|string',
            'shipping_address.district' => 'required|string',
            'shipping_address.postal_code' => 'nullable|string',
            'billing_address' => 'nullable|array',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();

        try {
            // If items provided directly (not from cart)
            if (isset($validated['items']) && ! empty($validated['items'])) {
                return $this->createOrderFromItems($request, $validated);
            }

            // Otherwise, create from cart
            $cart = $request->user()->cart()->with('items.product')->firstOrFail();

            if ($cart->items->isEmpty()) {
                return response()->json([
                    'message' => 'Cart is empty.',
                ], 422);
            }

            $storeId = $cart->items->first()->product->store_id;

            $order = Order::create([
                'order_number' => 'ORD-'.strtoupper(uniqid()),
                'user_id' => $request->user()->id,
                'store_id' => $storeId,
                'status' => Order::STATUS_PENDING,
                'payment_method' => $validated['payment_method'],
                'payment_status' => 'pending',
                'subtotal_ugx' => $cart->total_ugx,
                'subtotal_credits' => $cart->total_credits,
                'total_ugx' => $cart->total_ugx,
                'total_credits' => $cart->total_credits,
                'subtotal' => $cart->total_ugx,
                'total_amount' => $cart->total_ugx,
                'shipping_address' => $validated['shipping_address'],
                'billing_address' => $validated['billing_address'] ?? $validated['shipping_address'],
                'customer_notes' => $validated['notes'] ?? null,
            ]);

            foreach ($cart->items as $cartItem) {
                $order->items()->create([
                    'product_id' => $cartItem->product_id,
                    'product_name' => $cartItem->product->name,
                    'quantity' => $cartItem->quantity,
                    'price_ugx' => $cartItem->price_ugx,
                    'unit_price' => $cartItem->price_ugx,
                    'price_credits' => $cartItem->price_credits,
                    'payment_method' => $cartItem->payment_method,
                    'subtotal' => $cartItem->price_ugx * $cartItem->quantity,
                    'total_amount' => $cartItem->price_ugx * $cartItem->quantity,
                ]);

                if ($cartItem->product->track_inventory) {
                    $cartItem->product->decrement('inventory_quantity', $cartItem->quantity);
                }
            }

            $cart->items()->delete();
            $cart->updateTotals();

            if ($validated['payment_method'] === 'credits') {
                if ($request->user()->credits < $order->total_credits) {
                    throw new \Exception('Insufficient credits');
                }
                $request->user()->decrement('credits', $order->total_credits);
                $order->update(['payment_status' => 'paid']);
            }

            DB::commit();

            return response()->json([
                'data' => $order->load('items.product', 'store'),
                'message' => 'Order created successfully.',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Create order directly from items (without cart).
     */
    protected function createOrderFromItems(Request $request, array $validated): JsonResponse
    {
        $items = collect($validated['items']);

        $productIds = $items->pluck('product_id');
        $products = \App\Modules\Store\Models\Product::whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($items as $item) {
            if (! $products->has($item['product_id'])) {
                return response()->json([
                    'message' => 'Product not found: '.$item['product_id'],
                ], 422);
            }
        }

        $subtotal = 0;
        foreach ($items as $item) {
            $product = $products[$item['product_id']];
            $subtotal += $product->price_ugx * $item['quantity'];
        }

        $firstProduct = $products->first();
        $storeId = $firstProduct->store_id;

        $order = Order::create([
            'order_number' => 'ORD-'.strtoupper(uniqid()),
            'user_id' => $request->user()->id,
            'store_id' => $storeId,
            'status' => Order::STATUS_PENDING,
            'payment_method' => $validated['payment_method'],
            'payment_status' => 'pending',
            'subtotal_ugx' => $subtotal,
            'total_ugx' => $subtotal,
            'subtotal' => $subtotal,
            'total_amount' => $subtotal,
            'subtotal_credits' => 0,
            'total_credits' => 0,
            'shipping_address' => $validated['shipping_address'],
            'billing_address' => $validated['billing_address'] ?? $validated['shipping_address'],
            'customer_notes' => $validated['notes'] ?? null,
        ]);

        foreach ($items as $item) {
            $product = $products[$item['product_id']];

            $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => $item['quantity'],
                'price_ugx' => $product->price_ugx,
                'unit_price' => $product->price_ugx,
                'price_credits' => 0,
                'payment_method' => $validated['payment_method'],
                'subtotal' => $product->price_ugx * $item['quantity'],
                'total_amount' => $product->price_ugx * $item['quantity'],
            ]);

            if ($product->track_inventory) {
                $product->decrement('inventory_quantity', $item['quantity']);
            }
        }

        if ($validated['payment_method'] === 'credits') {
            if ($request->user()->credits < $order->total_credits) {
                throw new \Exception('Insufficient credits');
            }
            $request->user()->decrement('credits', $order->total_credits);
            $order->update(['payment_status' => 'paid']);
        }

        DB::commit();

        return response()->json([
            'data' => $order->load('items.product', 'store'),
            'message' => 'Order created successfully.',
        ], 201);
    }

    /**
     * Cancel order.
     */
    public function cancel(Request $request, string $orderNumber): JsonResponse
    {
        $order = $request->user()->orders()
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        if (! in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING])) {
            return response()->json([
                'message' => 'Order cannot be cancelled.',
            ], 422);
        }

        $order->update(['status' => Order::STATUS_CANCELLED]);

        foreach ($order->items as $item) {
            if ($item->product->track_inventory) {
                $item->product->increment('inventory_quantity', $item->quantity);
            }
        }

        return response()->json([
            'message' => 'Order cancelled successfully.',
        ]);
    }

    /**
     * Get order tracking.
     */
    public function tracking(Request $request, string $orderNumber): JsonResponse
    {
        $order = $request->user()->orders()
            ->where('order_number', $orderNumber)
            ->with('tracking')
            ->firstOrFail();

        return response()->json([
            'data' => [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'tracking' => $order->tracking,
            ],
        ]);
    }

    /**
     * Get seller's orders.
     */
    public function sellerOrders(Request $request): JsonResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return response()->json([
                'message' => 'No store found.',
            ], 404);
        }

        $query = $store->orders()
            ->with([
                'items.product',
                'user:id,display_name,email',
            ])
            ->orderByDesc('created_at');

        if ($status = $request->status) {
            $query->where('status', $status);
        }

        $orders = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    /**
     * Update order status (seller).
     */
    public function updateStatus(Request $request, string $orderNumber): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:processing,shipped,delivered,cancelled',
            'tracking_number' => 'nullable|string',
            'carrier' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $store = $request->user()->store;
        $order = $store->orders()->where('order_number', $orderNumber)->firstOrFail();

        $order->update(['status' => $validated['status']]);

        if (isset($validated['tracking_number'])) {
            $order->tracking()->create([
                'status' => $validated['status'],
                'location' => $validated['carrier'] ?? 'Unknown',
                'notes' => $validated['notes'] ?? null,
            ]);
        }

        return response()->json([
            'message' => 'Order status updated successfully.',
        ]);
    }
}
