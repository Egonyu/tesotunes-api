<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class StoreApiController extends Controller
{
    use HandlesApiErrors;

    protected function ensureAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole(['admin', 'super_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Admin access is required for this action.',
            ], 403);
        }

        return null;
    }

    /**
     * Get store statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () {
            $data = Cache::remember('admin:store:stats', now()->addMinutes(5), function () {
                $revenueThisMonth = Order::where('status', 'completed')
                    ->whereMonth('created_at', date('m'))
                    ->whereYear('created_at', date('Y'))
                    ->sum('total_amount') ?? 0;

                $lastMonthRevenue = Order::where('status', 'completed')
                    ->whereMonth('created_at', date('m', strtotime('-1 month')))
                    ->whereYear('created_at', date('Y', strtotime('-1 month')))
                    ->sum('total_amount') ?? 0;

                $growthPercentage = $lastMonthRevenue > 0
                    ? (($revenueThisMonth - $lastMonthRevenue) / $lastMonthRevenue) * 100
                    : 0;

                return [
                    'total_products' => Product::count(),
                    'orders_this_month' => Order::whereMonth('created_at', date('m'))
                        ->whereYear('created_at', date('Y'))
                        ->count(),
                    'revenue_this_month' => $revenueThisMonth,
                    'growth_percentage' => round($growthPercentage, 1),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        }, 'Failed to fetch store statistics.');
    }

    /**
     * Get products list.
     */
    public function products(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->get('per_page', 10), 100);

            $products = Product::with(['store.user:id,username'])
                ->withCount('orderItems as sold')
                ->when($request->get('search'), function ($q) use ($request) {
                    $search = addcslashes($request->get('search'), '%_');
                    $q->where(function ($query) use ($search) {
                        $query->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('description', 'LIKE', "%{$search}%");
                    });
                })
                ->when($request->get('category') && $request->get('category') !== 'all', function ($q) use ($request) {
                    $q->where('product_type', $request->get('category'));
                })
                ->when($request->get('status') && $request->get('status') !== 'all', function ($q) use ($request) {
                    $q->where('status', $request->get('status'));
                })
                ->latest()
                ->paginate($perPage);

            $data = $products->through(function (Product $product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price_ugx,
                    'stock' => $product->stock_quantity,
                    'status' => $product->status,
                    'category' => $product->product_type,
                    'image' => $product->featured_image,
                    'artist' => $product->store?->user?->username ?? $product->store?->name,
                    'sold' => $product->sold ?? 0,
                    'created_at' => $product->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data->items(),
                'meta' => [
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                ],
            ]);
        }, 'Failed to fetch products.');
    }

    /**
     * Get stores/shops list.
     */
    public function shops(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->get('per_page', 10), 100);

            $shops = Store::with('user:id,username,email')
                ->withCount('products')
                ->latest()
                ->paginate($perPage);

            $data = $shops->through(function (Store $shop) {
                return [
                    ...$shop->toArray(),
                    'owner_name' => $shop->user?->username,
                    'owner_email' => $shop->user?->email,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data->items(),
                'meta' => [
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                ],
            ]);
        }, 'Failed to fetch shops.');
    }

    /**
     * Get orders list.
     */
    public function orders(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->get('per_page', 10), 100);

            $orders = Order::with(['user:id,username,email', 'store:id,name'])
                ->when($request->get('status') && $request->get('status') !== 'all', function ($q) use ($request) {
                    $q->where('status', $request->get('status'));
                })
                ->latest()
                ->paginate($perPage);

            $data = $orders->through(function (Order $order) {
                return [
                    ...$order->toArray(),
                    'customer_name' => $order->user?->username,
                    'customer_email' => $order->user?->email,
                    'store_name' => $order->store?->name,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data->items(),
                'meta' => [
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                ],
            ]);
        }, 'Failed to fetch orders.');
    }

    /**
     * Delete a product (soft-delete).
     */
    public function deleteProduct(Request $request, $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($id) {
            $product = Product::findOrFail($id);
            $product->delete();

            return response()->json(['success' => true, 'message' => 'Product deleted successfully']);
        }, 'Failed to delete product.');
    }

    /**
     * Toggle product status.
     */
    public function toggleProductStatus(Request $request, $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($id) {
            $product = Product::findOrFail($id);
            $newStatus = $product->status === 'active' ? 'draft' : 'active';
            $product->update(['status' => $newStatus]);

            return response()->json([
                'success' => true,
                'message' => 'Product status updated.',
                'data' => ['status' => $newStatus],
            ]);
        }, 'Failed to toggle product status.');
    }

    /**
     * Toggle shop status.
     */
    public function toggleShopStatus(Request $request, $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($id) {
            $shop = Store::findOrFail($id);
            $newStatus = $shop->status === 'active' ? 'suspended' : 'active';
            $shop->update(['status' => $newStatus]);

            return response()->json([
                'success' => true,
                'message' => 'Shop status updated.',
                'data' => ['status' => $newStatus],
            ]);
        }, 'Failed to toggle shop status.');
    }

    /**
     * Approve shop.
     */
    public function approveShop(Request $request, $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($id) {
            $shop = Store::findOrFail($id);
            $shop->update([
                'status' => 'active',
                'is_verified' => true,
                'verified_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Shop approved successfully.',
            ]);
        }, 'Failed to approve shop.');
    }

    /**
     * Suspend shop.
     */
    public function suspendShop(Request $request, $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($id) {
            $shop = Store::findOrFail($id);
            $shop->update([
                'status' => 'suspended',
                'suspended_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Shop suspended successfully.',
            ]);
        }, 'Failed to suspend shop.');
    }

    /**
     * Verify shop.
     */
    public function verifyShop(Request $request, $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($id) {
            $shop = Store::findOrFail($id);
            $shop->update([
                'is_verified' => true,
                'verified_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Shop verified successfully.',
            ]);
        }, 'Failed to verify shop.');
    }

    /**
     * Unverify shop.
     */
    public function unverifyShop(Request $request, $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($id) {
            $shop = Store::findOrFail($id);
            $shop->update([
                'is_verified' => false,
                'verified_at' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Shop verification removed.',
            ]);
        }, 'Failed to unverify shop.');
    }

    /**
     * Delete shop (soft-delete).
     */
    public function deleteShop(Request $request, $id): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($id) {
            $shop = Store::findOrFail($id);
            $shop->delete();

            return response()->json(['success' => true, 'message' => 'Shop deleted successfully']);
        }, 'Failed to delete shop.');
    }

    /**
     * Create a product.
     */
    public function createProduct(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($request) {
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

            $product = Product::create([
                'uuid' => (string) Str::uuid(),
                'store_id' => $validated['store_id'],
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']).'-'.Str::random(6),
                'description' => $validated['description'] ?? null,
                'short_description' => $validated['short_description'] ?? null,
                'product_type' => $validated['product_type'] ?? 'physical',
                'price_ugx' => $validated['price_ugx'],
                'price_credits' => $validated['price_credits'] ?? null,
                'stock_quantity' => $validated['stock_quantity'] ?? 0,
                'category_id' => $validated['category_id'] ?? null,
                'featured_image' => $validated['featured_image'] ?? null,
                'images' => $validated['images'] ?? null,
                'status' => $validated['status'] ?? 'draft',
            ]);

            return response()->json([
                'success' => true,
                'data' => $product,
                'message' => 'Product created successfully.',
            ], 201);
        }, 'Failed to create product.');
    }

    /**
     * Update a product.
     */
    public function updateProduct(Request $request, $product): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($request, $product) {
            $existing = Product::findOrFail($product);

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

            $existing->update(array_filter($validated, fn ($v) => $v !== null));

            return response()->json([
                'success' => true,
                'data' => $existing->fresh(),
                'message' => 'Product updated successfully.',
            ]);
        }, 'Failed to update product.');
    }

    /**
     * Update order status.
     */
    public function updateOrderStatus(Request $request, $order): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($request, $order) {
            $existing = Order::findOrFail($order);

            $validated = $request->validate([
                'status' => 'required|string|in:pending,processing,shipped,delivered,completed,cancelled',
                'admin_notes' => 'nullable|string',
                'tracking_number' => 'nullable|string',
                'shipping_provider' => 'nullable|string',
            ]);

            $updateData = ['status' => $validated['status']];

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

            $existing->update($updateData);

            return response()->json([
                'success' => true,
                'data' => $existing->fresh(),
                'message' => 'Order status updated successfully.',
            ]);
        }, 'Failed to update order status.');
    }

    /**
     * Create a shop/store.
     */
    public function createShop(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($request) {
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

            $shop = Store::create([
                'user_id' => $validated['user_id'],
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']).'-'.Str::random(6),
                'description' => $validated['description'] ?? null,
                'store_type' => $validated['store_type'] ?? 'user',
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
                'country' => $validated['country'] ?? null,
                'status' => $validated['status'] ?? 'pending',
            ]);

            return response()->json([
                'success' => true,
                'data' => $shop,
                'message' => 'Shop created successfully.',
            ], 201);
        }, 'Failed to create shop.');
    }

    /**
     * Update a shop/store.
     */
    public function updateShop(Request $request, $store): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () use ($request, $store) {
            $existing = Store::findOrFail($store);

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

            $existing->update(array_filter($validated, fn ($v) => $v !== null));

            return response()->json([
                'success' => true,
                'data' => $existing->fresh(),
                'message' => 'Shop updated successfully.',
            ]);
        }, 'Failed to update shop.');
    }

    /**
     * Get analytics.
     */
    public function analytics(Request $request): JsonResponse
    {
        if ($response = $this->ensureAdmin($request)) {
            return $response;
        }

        return $this->handleApiAction(function () {
            return response()->json([
                'success' => true,
                'data' => [
                    'total_shops' => Store::count(),
                    'active_shops' => Store::where('status', 'active')->count(),
                    'pending_shops' => Store::where('status', 'pending')->count(),
                    'total_products' => Product::count(),
                    'total_orders' => Order::count(),
                    'total_revenue' => Order::where('status', 'completed')->sum('total_amount') ?? 0,
                ],
            ]);
        }, 'Failed to fetch analytics.');
    }
}
