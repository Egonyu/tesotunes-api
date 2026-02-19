<?php

namespace App\Modules\Store\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\StoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store API Controller
 *
 * RESTful API endpoints for store management
 */
class StoreController extends Controller
{
    public function __construct(
        protected StoreService $storeService
    ) {}

    /**
     * List all active stores
     */
    public function index(Request $request): JsonResponse
    {
        $query = Store::with('owner:id,display_name,email')
            ->active()
            ->withCount('products', 'activeProducts');

        if ($search = $request->search) {
            $query->search($search);
        }

        if ($storeType = $request->store_type) {
            $query->where('store_type', $storeType);
        }

        if ($tier = $request->subscription_tier) {
            $query->where('subscription_tier', $tier);
        }

        $sortField = $request->get('sort', 'created_at');
        $sortOrder = $request->get('order', 'desc');
        $query->orderBy($sortField, $sortOrder);

        $stores = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => $stores->items(),
            'meta' => [
                'current_page' => $stores->currentPage(),
                'total' => $stores->total(),
                'per_page' => $stores->perPage(),
                'last_page' => $stores->lastPage(),
            ],
        ]);
    }

    /**
     * Get store details
     */
    public function show(string $identifier): JsonResponse
    {
        $store = Store::where('slug', $identifier)
            ->orWhere('uuid', $identifier)
            ->with([
                'owner:id,display_name,email',
                'activeProducts' => fn ($q) => $q->take(8),
            ])
            ->withCount('products', 'activeProducts', 'reviews')
            ->firstOrFail();

        return response()->json([
            'data' => $store,
        ]);
    }

    /**
     * Create a new store
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'logo' => 'nullable|image|max:2048',
            'banner' => 'nullable|image|max:5120',
        ]);

        try {
            $store = $this->storeService->create($request->user(), $validated);

            return response()->json([
                'data' => $store->load('owner:id,display_name,email'),
                'message' => 'Store created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update store
     */
    public function update(Request $request, Store $store): JsonResponse
    {
        $this->authorize('update', $store);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'logo' => 'nullable|image|max:2048',
            'banner' => 'nullable|image|max:5120',
            'settings' => 'nullable|array',
        ]);

        try {
            $updated = $this->storeService->update($store, $validated);

            return response()->json([
                'data' => $updated->load('owner:id,display_name,email'),
                'message' => 'Store updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get store statistics
     */
    public function statistics(Store $store): JsonResponse
    {
        $this->authorize('view', $store);

        $stats = $this->storeService->getStatistics($store);

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Featured stores
     */
    public function featured(Request $request): JsonResponse
    {
        $stores = Store::featured()
            ->with('owner:id,display_name,email')
            ->take($request->get('limit', 10))
            ->get();

        return response()->json([
            'data' => $stores,
        ]);
    }
}
