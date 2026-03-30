<?php

namespace App\Modules\Store\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Store\Http\Requests\CreateStoreRequest;
use App\Modules\Store\Http\Requests\UpdateStoreRequest;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\StoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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
        $query = Store::with(['owner', 'user:id,display_name,email'])
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

        $stores = $query->paginate($this->getPerPage($request));

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
    public function show(Request $request, string $identifier): JsonResponse
    {
        $query = Store::where('slug', $identifier)
            ->orWhere('uuid', $identifier)
            ->with([
                'owner',
                'user:id,display_name,email',
                'activeProducts' => fn ($q) => $q->take(8),
            ])
            ->withCount('products', 'activeProducts');

        if (Schema::hasTable('reviews')) {
            $query->withCount('approvedGenericReviews as reviews_count');
        }

        $store = $query->firstOrFail();

        if ($store->status !== Store::STATUS_ACTIVE) {
            $viewer = $request->user();

            if (! $viewer || (! $store->canBeManagedBy($viewer) && ! $viewer->hasAnyRole(['admin', 'super_admin']))) {
                abort(404);
            }
        }

        return response()->json([
            'data' => $store,
        ]);
    }

    /**
     * List stores managed by the authenticated seller.
     */
    public function mine(Request $request): JsonResponse
    {
        $stores = Store::query()
            ->managedByUser($request->user())
            ->with(['owner', 'user:id,display_name,email'])
            ->withCount('products', 'activeProducts', 'orders')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $stores,
        ]);
    }

    /**
     * Create a new store
     */
    public function store(CreateStoreRequest $request): JsonResponse
    {
        try {
            $store = $this->storeService->create($request->user(), $request->validated());

            return response()->json([
                'data' => $store->load(['owner', 'user:id,display_name,email']),
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
    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        $this->authorize('update', $store);

        try {
            $updated = $this->storeService->update($store, $request->validated());

            return response()->json([
                'data' => $updated->load(['owner', 'user:id,display_name,email']),
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
        $this->authorize('viewStatistics', $store);

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
            ->with(['owner', 'user:id,display_name,email'])
            ->take($request->get('limit', 10))
            ->get();

        return response()->json([
            'data' => $stores,
        ]);
    }

    /**
     * Activate a seller store.
     */
    public function activate(Store $store): JsonResponse
    {
        $this->authorize('activate', $store);

        $this->storeService->activate($store);

        return response()->json([
            'data' => $store->fresh(['owner', 'user:id,display_name,email']),
            'message' => 'Store activated successfully',
        ]);
    }

    /**
     * Suspend a store.
     */
    public function suspend(Request $request, Store $store): JsonResponse
    {
        $this->authorize('suspend', $store);

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $this->storeService->suspend($store, $validated['reason']);

        return response()->json([
            'data' => $store->fresh(['owner', 'user:id,display_name,email']),
            'message' => 'Store suspended successfully',
        ]);
    }
}
