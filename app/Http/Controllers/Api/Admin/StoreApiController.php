<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreApiController extends Controller
{
    /**
     * Get store statistics.
     */
    public function stats()
    {
        $totalProducts = DB::table('store_products')->count();
        $ordersThisMonth = DB::table('orders')
            ->whereMonth('created_at', date('m'))
            ->whereYear('created_at', date('Y'))
            ->count();

        $revenueThisMonth = DB::table('orders')
            ->where('status', 'completed')
            ->whereMonth('created_at', date('m'))
            ->whereYear('created_at', date('Y'))
            ->sum('total_amount') ?? 0;

        $lastMonthRevenue = DB::table('orders')
            ->where('status', 'completed')
            ->whereMonth('created_at', date('m', strtotime('-1 month')))
            ->whereYear('created_at', date('Y', strtotime('-1 month')))
            ->sum('total_amount') ?? 0;

        $growthPercentage = $lastMonthRevenue > 0
            ? (($revenueThisMonth - $lastMonthRevenue) / $lastMonthRevenue) * 100
            : 0;

        return response()->json([
            'data' => [
                'total_products' => $totalProducts,
                'orders_this_month' => $ordersThisMonth,
                'revenue_this_month' => $revenueThisMonth,
                'growth_percentage' => round($growthPercentage, 1),
            ],
        ]);
    }

    /**
     * Get products list.
     */
    public function products(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $search = $request->get('search');
        $category = $request->get('category');
        $status = $request->get('status');

        $query = DB::table('store_products')
            ->leftJoin('stores', 'store_products.store_id', '=', 'stores.id')
            ->leftJoin('users', 'stores.user_id', '=', 'users.id')
            ->select(
                'store_products.id',
                'store_products.name',
                'store_products.price_ugx as price',
                'store_products.stock_quantity as stock',
                'store_products.status',
                'store_products.product_type as category',
                'store_products.featured_image as image',
                DB::raw('COALESCE(users.username, stores.name) as artist'),
                DB::raw('COALESCE((SELECT COUNT(*) FROM order_items WHERE product_id = store_products.id), 0) as sold')
            );

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('store_products.name', 'LIKE', "%{$search}%")
                    ->orWhere('store_products.description', 'LIKE', "%{$search}%");
            });
        }

        if ($category && $category !== 'all') {
            $query->where('store_products.product_type', $category);
        }

        if ($status && $status !== 'all') {
            $query->where('store_products.status', $status);
        }

        $products = $query->orderBy('store_products.created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /**
     * Get stores/shops list.
     */
    public function shops(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        $shops = DB::table('stores')
            ->leftJoin('users', 'stores.user_id', '=', 'users.id')
            ->select(
                'stores.*',
                'users.username as owner_name',
                'users.email as owner_email',
                DB::raw('(SELECT COUNT(*) FROM store_products WHERE store_id = stores.id) as products_count'),
                DB::raw('(SELECT SUM(total) FROM store_orders WHERE store_id = stores.id AND status = "completed") as total_revenue')
            )
            ->orderBy('stores.created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $shops->items(),
            'meta' => [
                'current_page' => $shops->currentPage(),
                'last_page' => $shops->lastPage(),
                'per_page' => $shops->perPage(),
                'total' => $shops->total(),
            ],
        ]);
    }

    /**
     * Get orders list.
     */
    public function orders(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $status = $request->get('status');

        $query = DB::table('orders')
            ->leftJoin('users', 'orders.user_id', '=', 'users.id')
            ->leftJoin('stores', 'orders.store_id', '=', 'stores.id')
            ->select(
                'orders.*',
                'users.username as customer_name',
                'users.email as customer_email',
                'stores.name as store_name'
            );

        if ($status && $status !== 'all') {
            $query->where('orders.status', $status);
        }

        $orders = $query->orderBy('orders.created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Delete a product.
     */
    public function deleteProduct($id)
    {
        DB::table('store_products')->where('id', $id)->delete();

        return response()->json(null, 204);
    }

    /**
     * Toggle product status.
     */
    public function toggleProductStatus($id)
    {
        $product = DB::table('store_products')->where('id', $id)->first();

        if (! $product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $newStatus = $product->status === 'active' ? 'draft' : 'active';

        DB::table('store_products')
            ->where('id', $id)
            ->update(['status' => $newStatus]);

        return response()->json([
            'message' => 'Product status updated.',
            'data' => ['status' => $newStatus],
        ]);
    }

    /**
     * Toggle shop status.
     */
    public function toggleShopStatus($id)
    {
        $shop = DB::table('stores')->where('id', $id)->first();

        if (! $shop) {
            return response()->json([
                'message' => 'Shop not found.',
            ], 404);
        }

        $newStatus = $shop->status === 'active' ? 'suspended' : 'active';

        DB::table('stores')
            ->where('id', $id)
            ->update(['status' => $newStatus]);

        return response()->json([
            'message' => 'Shop status updated.',
            'data' => ['status' => $newStatus],
        ]);
    }

    /**
     * Approve shop.
     */
    public function approveShop($id)
    {
        DB::table('stores')
            ->where('id', $id)
            ->update([
                'status' => 'active',
                'is_verified' => true,
                'verified_at' => now(),
            ]);

        return response()->json([
            'message' => 'Shop approved successfully.',
        ]);
    }

    /**
     * Suspend shop.
     */
    public function suspendShop($id)
    {
        DB::table('stores')
            ->where('id', $id)
            ->update([
                'status' => 'suspended',
                'suspended_at' => now(),
            ]);

        return response()->json([
            'message' => 'Shop suspended successfully.',
        ]);
    }

    /**
     * Verify shop.
     */
    public function verifyShop($id)
    {
        DB::table('stores')
            ->where('id', $id)
            ->update([
                'is_verified' => true,
                'verified_at' => now(),
            ]);

        return response()->json([
            'message' => 'Shop verified successfully.',
        ]);
    }

    /**
     * Unverify shop.
     */
    public function unverifyShop($id)
    {
        DB::table('stores')
            ->where('id', $id)
            ->update([
                'is_verified' => false,
                'verified_at' => null,
            ]);

        return response()->json([
            'message' => 'Shop verification removed.',
        ]);
    }

    /**
     * Delete shop.
     */
    public function deleteShop($id)
    {
        DB::table('stores')->where('id', $id)->delete();

        return response()->json(null, 204);
    }

    /**
     * Create a product.
     */
    public function createProduct(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'required|integer|exists:stores,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string|max:500',
            'product_type' => 'nullable|string|in:physical,digital,service,experience,ticket,promotion',
            'price_ugx' => 'required|numeric|min:0',
            'price_credits' => 'nullable|integer|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'category_id' => 'nullable|integer',
            'featured_image' => 'nullable|string',
            'images' => 'nullable|array',
            'status' => 'nullable|string|in:draft,active',
        ]);

        $id = DB::table('store_products')->insertGetId([
            'store_id' => $validated['store_id'],
            'name' => $validated['name'],
            'slug' => \Illuminate\Support\Str::slug($validated['name']).'-'.\Illuminate\Support\Str::random(6),
            'description' => $validated['description'] ?? null,
            'short_description' => $validated['short_description'] ?? null,
            'product_type' => $validated['product_type'] ?? 'physical',
            'price_ugx' => $validated['price_ugx'],
            'price_credits' => $validated['price_credits'] ?? null,
            'stock_quantity' => $validated['stock_quantity'] ?? 0,
            'category_id' => $validated['category_id'] ?? null,
            'featured_image' => $validated['featured_image'] ?? null,
            'images' => isset($validated['images']) ? json_encode($validated['images']) : null,
            'status' => $validated['status'] ?? 'draft',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = DB::table('store_products')->where('id', $id)->first();

        return response()->json([
            'data' => $product,
            'message' => 'Product created successfully.',
        ], 201);
    }

    /**
     * Update a product.
     */
    public function updateProduct(Request $request, $product)
    {
        $existing = DB::table('store_products')->where('id', $product)->first();

        if (! $existing) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string|max:500',
            'product_type' => 'nullable|string|in:physical,digital,service,experience,ticket,promotion',
            'price_ugx' => 'sometimes|numeric|min:0',
            'price_credits' => 'nullable|integer|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'category_id' => 'nullable|integer',
            'featured_image' => 'nullable|string',
            'images' => 'nullable|array',
            'status' => 'nullable|string|in:draft,active,archived',
        ]);

        $updateData = array_filter($validated, fn ($v) => $v !== null);
        $updateData['updated_at'] = now();

        if (isset($updateData['images'])) {
            $updateData['images'] = json_encode($updateData['images']);
        }

        DB::table('store_products')->where('id', $product)->update($updateData);

        $updated = DB::table('store_products')->where('id', $product)->first();

        return response()->json([
            'data' => $updated,
            'message' => 'Product updated successfully.',
        ]);
    }

    /**
     * Update order status.
     */
    public function updateOrderStatus(Request $request, $order)
    {
        $existing = DB::table('orders')->where('id', $order)->first();

        if (! $existing) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:pending,processing,shipped,delivered,completed,cancelled',
            'admin_notes' => 'nullable|string',
            'tracking_number' => 'nullable|string',
            'shipping_provider' => 'nullable|string',
        ]);

        $updateData = ['status' => $validated['status'], 'updated_at' => now()];

        if (isset($validated['admin_notes'])) {
            $updateData['admin_notes'] = $validated['admin_notes'];
        }
        if (isset($validated['tracking_number'])) {
            $updateData['tracking_number'] = $validated['tracking_number'];
        }
        if (isset($validated['shipping_provider'])) {
            $updateData['shipping_provider'] = $validated['shipping_provider'];
        }

        // Set timestamp columns based on status
        match ($validated['status']) {
            'shipped' => $updateData['shipped_at'] = now(),
            'delivered' => $updateData['delivered_at'] = now(),
            'completed' => $updateData['completed_at'] = now(),
            default => null,
        };

        DB::table('orders')->where('id', $order)->update($updateData);

        $updated = DB::table('orders')->where('id', $order)->first();

        return response()->json([
            'data' => $updated,
            'message' => 'Order status updated successfully.',
        ]);
    }

    /**
     * Create a shop/store.
     */
    public function createShop(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'store_type' => 'nullable|string|in:artist,user',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:pending,active',
        ]);

        $id = DB::table('stores')->insertGetId([
            'user_id' => $validated['user_id'],
            'name' => $validated['name'],
            'slug' => \Illuminate\Support\Str::slug($validated['name']).'-'.\Illuminate\Support\Str::random(6),
            'description' => $validated['description'] ?? null,
            'store_type' => $validated['store_type'] ?? 'user',
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'country' => $validated['country'] ?? null,
            'status' => $validated['status'] ?? 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shop = DB::table('stores')->where('id', $id)->first();

        return response()->json([
            'data' => $shop,
            'message' => 'Shop created successfully.',
        ], 201);
    }

    /**
     * Update a shop/store.
     */
    public function updateShop(Request $request, $store)
    {
        $existing = DB::table('stores')->where('id', $store)->first();

        if (! $existing) {
            return response()->json(['message' => 'Shop not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'store_type' => 'nullable|string|in:artist,user',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:pending,active,suspended,closed',
        ]);

        $updateData = array_filter($validated, fn ($v) => $v !== null);
        $updateData['updated_at'] = now();

        DB::table('stores')->where('id', $store)->update($updateData);

        $updated = DB::table('stores')->where('id', $store)->first();

        return response()->json([
            'data' => $updated,
            'message' => 'Shop updated successfully.',
        ]);
    }

    /**
     * Get analytics.
     */
    public function analytics()
    {
        $totalShops = DB::table('stores')->count();
        $activeShops = DB::table('stores')->where('status', 'active')->count();
        $pendingShops = DB::table('stores')->where('status', 'pending')->count();
        $totalProducts = DB::table('store_products')->count();
        $totalOrders = DB::table('orders')->count();
        $totalRevenue = DB::table('orders')
            ->where('status', 'completed')
            ->sum('total_amount') ?? 0;

        return response()->json([
            'data' => [
                'total_shops' => $totalShops,
                'active_shops' => $activeShops,
                'pending_shops' => $pendingShops,
                'total_products' => $totalProducts,
                'total_orders' => $totalOrders,
                'total_revenue' => $totalRevenue,
            ],
        ]);
    }
}
