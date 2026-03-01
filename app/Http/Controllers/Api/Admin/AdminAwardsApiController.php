<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AwardCategoryResource;
use App\Http\Resources\AwardNominationResource;
use App\Http\Resources\AwardResource;
use App\Models\Award;
use App\Models\AwardCategory;
use App\Models\AwardNomination;
use App\Models\AwardVote;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminAwardsApiController extends Controller
{
    use HandlesApiErrors;
    // ========================================================================
    // Dashboard — stats + listing
    // ========================================================================

    /**
     * GET /api/admin/awards/stats
     */
    public function stats(): JsonResponse
    {
        return $this->handleApiAction(function () {
            return response()->json([
                'success' => true,
                'data' => [
                    'total_awards' => Award::count(),
                    'total_categories' => AwardCategory::count(),
                    'total_nominations' => AwardNomination::count(),
                    'total_votes' => AwardVote::count(),
                    'active_awards' => Award::active()->count(),
                    'pending_nominations' => AwardNomination::pending()->count(),
                ],
            ]);
        }, 'Failed to load award statistics.');
    }

    /**
     * GET /api/admin/awards
     * Master listing with embedded stats for the admin awards dashboard.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->get('per_page', 15), 100);

        $query = Award::withCount(['categories', 'nominations', 'votes'])
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->search.'%'))
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->latest('year')
            ->latest('created_at');

        $awards = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => AwardResource::collection($awards),
                'stats' => [
                    'total_awards' => Award::count(),
                    'total_categories' => AwardCategory::count(),
                    'total_nominations' => AwardNomination::count(),
                    'total_votes' => AwardVote::count(),
                ],
                'meta' => [
                    'current_page' => $awards->currentPage(),
                    'last_page' => $awards->lastPage(),
                    'per_page' => $awards->perPage(),
                    'total' => $awards->total(),
                ],
            ]);
        }, 'Failed to load awards.');
    }

    // ========================================================================
    // Award Seasons — CRUD
    // ========================================================================

    /**
     * GET /api/admin/awards/seasons
     */
    public function seasons(Request $request)
    {
        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->get('per_page', 15), 100);

        $awards = Award::withCount(['categories', 'nominations'])
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->search.'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('year')
            ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => AwardResource::collection($awards),
                'meta' => [
                    'current_page' => $awards->currentPage(),
                    'last_page' => $awards->lastPage(),
                    'per_page' => $awards->perPage(),
                    'total' => $awards->total(),
                ],
            ]);
        }, 'Failed to load award seasons.');
    }

    /**
     * GET /api/admin/awards/seasons/{id}
     */
    public function showSeason($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $award = Award::where('id', $id)
            ->orWhere('uuid', $id)
            ->orWhere('slug', $id)
            ->withCount(['categories', 'nominations', 'votes'])
            ->firstOrFail();

        $categories = AwardCategory::active()
            ->ordered()
            ->withCount(['nominations' => fn ($q) => $q->where('award_id', $award->id)])
            ->get();

        $nominations = AwardNomination::where('award_id', $award->id)
            ->with(['category', 'nominatedBy:id,username'])
            ->withCount('votes')
            ->latest()
            ->limit(50)
            ->get();

            return response()->json([
                'success' => true,
                'data' => new AwardResource($award),
                'categories' => AwardCategoryResource::collection($categories),
                'nominations' => AwardNominationResource::collection($nominations),
                'stats' => [
                    'total_categories' => $categories->count(),
                    'total_nominations' => $award->nominations_count,
                    'total_votes' => $award->votes_count,
                    'pending_nominations' => AwardNomination::where('award_id', $award->id)->pending()->count(),
                ],
            ]);
        }, 'Failed to load award season details.');
    }

    /**
     * POST /api/admin/awards/seasons
     */
    public function storeSeason(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
            'name' => 'required|string|max:255',
            'year' => 'required|integer|min:2020|max:2035',
            'description' => 'nullable|string',
            'season' => 'nullable|string|max:100',
            'nominations_start_at' => 'nullable|date',
            'nominations_end_at' => 'nullable|date',
            'voting_start_at' => 'nullable|date',
            'voting_end_at' => 'nullable|date',
            'ceremony_date' => 'nullable|date',
            'status' => 'nullable|string',
            'visibility' => 'nullable|in:public,private',
            'allow_public_nominations' => 'nullable|boolean',
            'allow_public_voting' => 'nullable|boolean',
            'votes_per_category' => 'nullable|integer|min:1|max:10',
        ]);

        $award = Award::create([
            'uuid' => (string) Str::uuid(),
            'title' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'year' => $validated['year'],
            'season' => $validated['season'] ?? null,
            'nomination_starts_at' => $validated['nominations_start_at'] ?? null,
            'nomination_ends_at' => $validated['nominations_end_at'] ?? null,
            'voting_starts_at' => $validated['voting_start_at'] ?? null,
            'voting_ends_at' => $validated['voting_end_at'] ?? null,
            'ceremony_date' => $validated['ceremony_date'] ?? null,
            'status' => $validated['status'] ?? 'upcoming',
            'visibility' => $validated['visibility'] ?? 'public',
            'allow_public_nominations' => $validated['allow_public_nominations'] ?? true,
            'allow_public_voting' => $validated['allow_public_voting'] ?? true,
            'votes_per_category' => $validated['votes_per_category'] ?? 1,
        ]);

            return response()->json([
                'success' => true,
                'data' => new AwardResource($award),
                'message' => 'Award created successfully.',
            ], 201);
        }, 'Failed to create award season.');
    }

    /**
     * PUT /api/admin/awards/seasons/{id}
     */
    public function updateSeason(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $award = Award::where('id', $id)->orWhere('uuid', $id)->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'year' => 'sometimes|integer|min:2020|max:2035',
            'description' => 'nullable|string',
            'season' => 'nullable|string|max:100',
            'nominations_start_at' => 'nullable|date',
            'nominations_end_at' => 'nullable|date',
            'voting_start_at' => 'nullable|date',
            'voting_end_at' => 'nullable|date',
            'ceremony_date' => 'nullable|date',
            'status' => 'nullable|string',
            'visibility' => 'nullable|in:public,private',
            'allow_public_nominations' => 'nullable|boolean',
            'allow_public_voting' => 'nullable|boolean',
            'votes_per_category' => 'nullable|integer|min:1|max:10',
        ]);

        // Map frontend field names to model fields
        $updateData = [];
        if (isset($validated['name'])) {
            $updateData['title'] = $validated['name'];
            $updateData['slug'] = Str::slug($validated['name']);
        }
        foreach (['year', 'description', 'season', 'status', 'visibility',
            'allow_public_nominations', 'allow_public_voting', 'votes_per_category'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updateData[$field] = $validated[$field];
            }
        }
        // Map date fields
        $dateMap = [
            'nominations_start_at' => 'nomination_starts_at',
            'nominations_end_at' => 'nomination_ends_at',
            'voting_start_at' => 'voting_starts_at',
            'voting_end_at' => 'voting_ends_at',
            'ceremony_date' => 'ceremony_date',
        ];
        foreach ($dateMap as $input => $column) {
            if (array_key_exists($input, $validated)) {
                $updateData[$column] = $validated[$input];
            }
        }

        $award->update($updateData);

            return response()->json([
                'success' => true,
                'data' => new AwardResource($award->fresh()),
                'message' => 'Award updated successfully.',
            ]);
        }, 'Failed to update award season.');
    }

    /**
     * DELETE /api/admin/awards/seasons/{id}
     */
    public function destroySeason($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $award = Award::where('id', $id)->orWhere('uuid', $id)->firstOrFail();

            if ($award->votes()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete award with existing votes.',
                ], 422);
            }

            $award->nominations()->delete();
            $award->delete();

            return response()->json([
                'success' => true,
                'message' => 'Award deleted successfully.',
            ]);
        }, 'Failed to delete award season.');
    }

    // ========================================================================
    // Award Categories — CRUD
    // ========================================================================

    /**
     * GET /api/admin/awards/categories
     */
    public function categories(Request $request)
    {
        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->get('per_page', 20), 100);

        $categories = AwardCategory::withCount('nominations')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->search.'%'))
            ->when($request->filled('type'), fn ($q) => $q->where('category_type', $request->type))
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('is_active', $request->status === 'active');
            })
            ->ordered()
            ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => AwardCategoryResource::collection($categories),
                'meta' => [
                    'current_page' => $categories->currentPage(),
                    'last_page' => $categories->lastPage(),
                    'per_page' => $categories->perPage(),
                    'total' => $categories->total(),
                ],
            ]);
        }, 'Failed to load award categories.');
    }

    /**
     * GET /api/admin/awards/categories/{id}
     */
    public function showCategory($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $category = AwardCategory::where('id', $id)
            ->orWhere('uuid', $id)
            ->withCount('nominations')
            ->firstOrFail();

        $nominations = AwardNomination::where('category_id', $category->id)
            ->with(['award', 'nominatedBy:id,username'])
            ->withCount('votes')
            ->latest()
            ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => new AwardCategoryResource($category),
                'nominations' => AwardNominationResource::collection($nominations),
                'stats' => [
                    'total_nominations' => $category->nominations_count,
                    'approved' => AwardNomination::where('category_id', $category->id)->approved()->count(),
                    'pending' => AwardNomination::where('category_id', $category->id)->pending()->count(),
                ],
                'meta' => [
                    'current_page' => $nominations->currentPage(),
                    'last_page' => $nominations->lastPage(),
                    'per_page' => $nominations->perPage(),
                    'total' => $nominations->total(),
                ],
            ]);
        }, 'Failed to load award category details.');
    }

    /**
     * POST /api/admin/awards/categories
     */
    public function storeCategory(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_type' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $category = AwardCategory::create([
            'uuid' => (string) Str::uuid(),
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'category_type' => $validated['category_type'] ?? 'general',
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

            return response()->json([
                'success' => true,
                'data' => new AwardCategoryResource($category),
                'message' => 'Category created successfully.',
            ], 201);
        }, 'Failed to create award category.');
    }

    /**
     * PUT /api/admin/awards/categories/{id}
     */
    public function updateCategory(Request $request, $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $category = AwardCategory::where('id', $id)->orWhere('uuid', $id)->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'category_type' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category->update($validated);

            return response()->json([
                'success' => true,
                'data' => new AwardCategoryResource($category->fresh()),
                'message' => 'Category updated successfully.',
            ]);
        }, 'Failed to update award category.');
    }

    /**
     * DELETE /api/admin/awards/categories/{id}
     */
    public function destroyCategory($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $category = AwardCategory::where('id', $id)->orWhere('uuid', $id)->firstOrFail();

            if ($category->nominations()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete category with existing nominations.',
                ], 422);
            }

            $category->delete();

            return response()->json([
                'success' => true,
                'message' => 'Category deleted successfully.',
            ]);
        }, 'Failed to delete award category.');
    }

    // ========================================================================
    // Nominations — list, create, approve, reject, set-winner
    // ========================================================================

    /**
     * GET /api/admin/awards/nominations
     */
    public function nominations(Request $request)
    {
        return $this->handleApiAction(function () use ($request) {
            $perPage = min((int) $request->get('per_page', 20), 100);

        $nominations = AwardNomination::with(['award', 'category', 'nominatedBy:id,username'])
            ->withCount('votes')
            ->when($request->filled('search'), fn ($q) => $q->where('nominee_name', 'like', '%'.$request->search.'%'))
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('award_id'), fn ($q) => $q->where('award_id', $request->award_id))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->category_id))
            ->latest()
            ->paginate($perPage);

        // Provide filter options
        $seasons = Award::select('id', 'title', 'year', 'status')->latest('year')->get();
        $categories = AwardCategory::select('id', 'name', 'category_type')->ordered()->get();

            return response()->json([
                'success' => true,
                'data' => AwardNominationResource::collection($nominations),
                'seasons' => $seasons,
                'categories' => $categories,
                'meta' => [
                    'current_page' => $nominations->currentPage(),
                    'last_page' => $nominations->lastPage(),
                    'per_page' => $nominations->perPage(),
                    'total' => $nominations->total(),
                ],
            ]);
        }, 'Failed to load nominations.');
    }

    /**
     * POST /api/admin/awards/nominations
     */
    public function storeNomination(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
            'award_id' => 'required|integer|exists:awards,id',
            'category_id' => 'required|integer|exists:award_categories,id',
            'nominee_name' => 'required|string|max:255',
            'nominee_type' => 'nullable|string|max:50',
            'nominee_id' => 'nullable|integer',
            'nominee_artwork' => 'nullable|string|max:500',
            'nomination_reason' => 'nullable|string|max:1000',
            'status' => 'nullable|in:pending,approved,rejected',
        ]);

        $nomination = AwardNomination::create([
            'uuid' => (string) Str::uuid(),
            'award_id' => $validated['award_id'],
            'category_id' => $validated['category_id'],
            'nominee_name' => $validated['nominee_name'],
            'nominee_type' => $validated['nominee_type'] ?? null,
            'nominee_id' => $validated['nominee_id'] ?? null,
            'nominee_artwork' => $validated['nominee_artwork'] ?? null,
            'nomination_reason' => $validated['nomination_reason'] ?? null,
            'nominated_by_id' => $request->user()->id,
            'status' => $validated['status'] ?? 'approved', // Admin nominations are auto-approved
            'is_official' => true,
            'approved_at' => ($validated['status'] ?? 'approved') === 'approved' ? now() : null,
        ]);

            return response()->json([
                'success' => true,
                'data' => new AwardNominationResource($nomination->load(['category', 'award'])),
                'message' => 'Nomination created successfully.',
            ], 201);
        }, 'Failed to create nomination.');
    }

    /**
     * POST /api/admin/awards/nominations/{id}/approve
     */
    public function approveNomination($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $nomination = AwardNomination::findOrFail($id);
            $nomination->update([
                'status' => 'approved',
                'approved_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => new AwardNominationResource($nomination->fresh()),
                'message' => 'Nomination approved.',
            ]);
        }, 'Failed to approve nomination.');
    }

    /**
     * POST /api/admin/awards/nominations/{id}/reject
     */
    public function rejectNomination($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $nomination = AwardNomination::findOrFail($id);
            $nomination->update(['status' => 'rejected']);

            return response()->json([
                'success' => true,
                'data' => new AwardNominationResource($nomination->fresh()),
                'message' => 'Nomination rejected.',
            ]);
        }, 'Failed to reject nomination.');
    }

    /**
     * POST /api/admin/awards/nominations/{id}/set-winner
     */
    public function setWinner($id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $nomination = AwardNomination::findOrFail($id);
            $nomination->update(['status' => 'winner']);

            return response()->json([
                'success' => true,
                'data' => new AwardNominationResource($nomination->fresh()),
                'message' => 'Winner declared!',
            ]);
        }, 'Failed to set winner.');
    }
}
