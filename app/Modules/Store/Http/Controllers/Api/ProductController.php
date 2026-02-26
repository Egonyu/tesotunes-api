<?php

namespace App\Modules\Store\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product API Controller
 */
class ProductController extends Controller
{
    /**
     * List products.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::with('store:id,name,slug', 'category:id,name')
            ->where('status', Product::STATUS_ACTIVE);

        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($categoryId = $request->category_id) {
            $query->where('category_id', $categoryId);
        }

        if ($storeId = $request->store_id) {
            $query->where('store_id', $storeId);
        }

        if ($productType = $request->product_type) {
            $query->where('product_type', $productType);
        }

        if ($request->has('featured')) {
            $query->where('is_featured', (bool) $request->featured);
        }

        if ($request->has('min_price_ugx')) {
            $query->where('price_ugx', '>=', $request->min_price_ugx);
        }

        if ($request->has('max_price_ugx')) {
            $query->where('price_ugx', '<=', $request->max_price_ugx);
        }

        if ($request->boolean('in_stock')) {
            $query->where(function ($q) {
                $q->where('track_inventory', false)
                    ->orWhere('inventory_quantity', '>', 0);
            });
        }

        $sortField = $request->get('sort', 'created_at');
        $sortOrder = $request->get('order', 'desc');

        $allowedSorts = ['created_at', 'price_ugx', 'name', 'total_sales', 'average_rating'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortOrder);
        }

        $products = $query->paginate($this->getPerPage($request));

        return response()->json([
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    /**
     * Get product details.
     */
    public function show(string $identifier): JsonResponse
    {
        $product = Product::where('slug', $identifier)
            ->orWhere('uuid', $identifier)
            ->with([
                'store:id,name,slug,uuid',
                'category:id,name',
            ])
            ->firstOrFail();

        $product->increment('views_count');

        return response()->json([
            'data' => $product,
        ]);
    }

    /**
     * Get products by store.
     */
    public function byStore(Store $store, Request $request): JsonResponse
    {
        $query = $store->products()
            ->where('status', Product::STATUS_ACTIVE);

        if ($categoryId = $request->category_id) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->paginate($this->getPerPage($request));

        return response()->json([
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'last_page' => $products->lastPage(),
            ],
        ]);
    }

    /**
     * Get featured products.
     */
    public function featured(Request $request): JsonResponse
    {
        $products = Product::where('status', Product::STATUS_ACTIVE)
            ->where('is_featured', true)
            ->with('store:id,name,slug', 'category:id,name')
            ->orderByDesc('total_sales')
            ->take($request->get('limit', 10))
            ->get();

        return response()->json([
            'data' => $products,
        ]);
    }

    /**
     * Get trending products.
     */
    public function trending(Request $request): JsonResponse
    {
        $products = Product::where('status', Product::STATUS_ACTIVE)
            ->where('created_at', '>=', now()->subDays(7))
            ->with('store:id,name,slug', 'category:id,name')
            ->orderByDesc('total_sales')
            ->take($request->get('limit', 10))
            ->get();

        return response()->json([
            'data' => $products,
        ]);
    }

    /**
     * Check product availability.
     */
    public function checkAvailability(Product $product): JsonResponse
    {
        $available = ! $product->track_inventory ||
                    $product->inventory_quantity > 0 ||
                    $product->allow_backorder;

        return response()->json([
            'data' => [
                'product_id' => $product->id,
                'available' => $available,
                'inventory_quantity' => $product->track_inventory ? $product->inventory_quantity : null,
                'allow_backorder' => $product->allow_backorder,
            ],
        ]);
    }
}
