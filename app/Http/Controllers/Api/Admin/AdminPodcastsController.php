<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PodcastEpisodeResource;
use App\Http\Resources\PodcastResource;
use App\Models\Podcast;
use App\Models\PodcastCategory;
use App\Models\PodcastEpisode;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AdminPodcastsController extends Controller
{
    use HandlesApiErrors;

    /**
     * GET /api/admin/podcasts/stats
     *
     * Dashboard-level stats for podcasts.
     */
    public function stats(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $data = Cache::remember('admin:podcasts:stats', now()->addMinutes(5), function () {
                return [
                    'total_podcasts' => Podcast::count(),
                    'published' => Podcast::where('status', 'published')->count(),
                    'draft' => Podcast::where('status', 'draft')->count(),
                    'pending_review' => Podcast::where('status', 'pending_review')->count(),
                    'suspended' => Podcast::where('status', 'suspended')->count(),
                    'total_episodes' => PodcastEpisode::count(),
                    'published_episodes' => PodcastEpisode::where('status', 'published')->count(),
                    'total_categories' => PodcastCategory::count(),
                    'premium_podcasts' => Podcast::where('is_premium', true)->count(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        }, 'Failed to retrieve podcast stats.');
    }

    /**
     * GET /api/admin/podcasts
     *
     * Paginated list of all podcasts with search/filter.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $query = Podcast::with(['creator:id,name,username', 'category:id,name'])
                ->withCount('episodes');

            // Search
            if ($search = $request->get('search')) {
                $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
                $query->where(function ($q) use ($escaped) {
                    $q->where('title', 'LIKE', "%{$escaped}%")
                        ->orWhere('description', 'LIKE', "%{$escaped}%")
                        ->orWhere('author_name', 'LIKE', "%{$escaped}%");
                });
            }

            // Filter by status
            if ($status = $request->get('status')) {
                if ($status !== 'all') {
                    $query->where('status', $status);
                }
            }

            // Filter by category
            if ($categoryId = $request->get('category_id')) {
                $query->where('podcast_category_id', $categoryId);
            }

            // Filter by premium
            if ($request->has('is_premium')) {
                $query->where('is_premium', $request->boolean('is_premium'));
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortDir = $request->get('sort_dir', 'desc');
            $allowedSorts = ['created_at', 'title', 'total_episodes', 'subscriber_count', 'total_listen_count', 'status'];
            if (in_array($sortBy, $allowedSorts, true)) {
                $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
            }

            $perPage = min((int) $request->get('per_page', 15), 100);
            $podcasts = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => PodcastResource::collection($podcasts)->resolve(),
                'meta' => [
                    'total' => $podcasts->total(),
                    'per_page' => $podcasts->perPage(),
                    'current_page' => $podcasts->currentPage(),
                    'last_page' => $podcasts->lastPage(),
                ],
            ]);
        }, 'Failed to retrieve podcasts.');
    }

    /**
     * GET /api/admin/podcasts/{id}
     *
     * Show a single podcast with all details.
     */
    public function show(int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $podcast = Podcast::with(['creator:id,name,username,email', 'category', 'episodes' => function ($q) {
                $q->orderByDesc('episode_number')->limit(20);
            }])
                ->withCount('episodes')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new PodcastResource($podcast),
            ]);
        }, 'Failed to retrieve podcast.');
    }

    /**
     * POST /api/admin/podcasts
     *
     * Create a new podcast (admin-created).
     */
    public function store(Request $request): JsonResponse
    {
        return $this->handleApiAction(function () use ($request) {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string|max:5000',
                'podcast_category_id' => 'nullable|integer|exists:podcast_categories,id',
                'user_id' => 'required|integer|exists:users,id',
                'artist_id' => 'nullable|integer|exists:artists,id',
                'language' => 'nullable|string|max:10',
                'is_explicit' => 'boolean',
                'is_premium' => 'boolean',
                'status' => 'nullable|in:draft,pending_review,published,suspended',
                'author_name' => 'nullable|string|max:255',
                'copyright' => 'nullable|string|max:255',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:50',
                'artwork' => 'nullable|string|max:500',
                'rss_feed_url' => 'nullable|url|max:500',
            ]);

            $validated['slug'] = \Illuminate\Support\Str::slug($validated['title']);
            $validated['status'] = $validated['status'] ?? 'draft';

            $podcast = Podcast::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Podcast created successfully.',
                'data' => new PodcastResource($podcast->load(['creator:id,name,username', 'category'])),
            ], 201);
        }, 'Failed to create podcast.');
    }

    /**
     * PUT /api/admin/podcasts/{id}
     *
     * Update an existing podcast.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $podcast = Podcast::findOrFail($id);

            $validated = $request->validate([
                'title' => 'sometimes|string|max:255',
                'description' => 'sometimes|string|max:5000',
                'podcast_category_id' => 'nullable|integer|exists:podcast_categories,id',
                'language' => 'nullable|string|max:10',
                'is_explicit' => 'boolean',
                'is_premium' => 'boolean',
                'status' => 'nullable|in:draft,pending_review,published,suspended',
                'author_name' => 'nullable|string|max:255',
                'copyright' => 'nullable|string|max:255',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:50',
                'artwork' => 'nullable|string|max:500',
                'rss_feed_url' => 'nullable|url|max:500',
            ]);

            if (isset($validated['title'])) {
                $validated['slug'] = \Illuminate\Support\Str::slug($validated['title']);
            }

            $podcast->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Podcast updated successfully.',
                'data' => new PodcastResource($podcast->fresh(['creator:id,name,username', 'category'])),
            ]);
        }, 'Failed to update podcast.');
    }

    /**
     * DELETE /api/admin/podcasts/{id}
     *
     * Soft-delete a podcast and its episodes.
     */
    public function destroy(int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $podcast = Podcast::findOrFail($id);
            $podcast->episodes()->delete();
            $podcast->delete();

            return response()->json([
                'success' => true,
                'message' => 'Podcast deleted successfully.',
            ]);
        }, 'Failed to delete podcast.');
    }

    /**
     * POST /api/admin/podcasts/{id}/approve
     *
     * Approve a pending podcast (set status to published).
     */
    public function approve(int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $podcast = Podcast::findOrFail($id);

            if ($podcast->status === 'published') {
                return response()->json([
                    'success' => false,
                    'message' => 'Podcast is already published.',
                ], 422);
            }

            $podcast->update([
                'status' => 'published',
                'published_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Podcast approved and published.',
                'data' => new PodcastResource($podcast->fresh(['creator:id,name,username', 'category'])),
            ]);
        }, 'Failed to approve podcast.');
    }

    /**
     * POST /api/admin/podcasts/{id}/suspend
     *
     * Suspend a podcast (set status to suspended).
     */
    public function suspend(Request $request, int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($id) {
            $podcast = Podcast::findOrFail($id);

            $podcast->update([
                'status' => 'suspended',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Podcast suspended.',
                'data' => new PodcastResource($podcast->fresh(['creator:id,name,username', 'category'])),
            ]);
        }, 'Failed to suspend podcast.');
    }

    /**
     * GET /api/admin/podcasts/{id}/episodes
     *
     * List episodes for a specific podcast.
     */
    public function episodes(Request $request, int $id): JsonResponse
    {
        return $this->handleApiAction(function () use ($request, $id) {
            $podcast = Podcast::findOrFail($id);

            $query = $podcast->episodes();

            if ($status = $request->get('status')) {
                $query->where('status', $status);
            }

            $sortBy = $request->get('sort_by', 'episode_number');
            $sortDir = $request->get('sort_dir', 'desc');
            $allowedSorts = ['episode_number', 'created_at', 'published_at', 'listen_count', 'title'];
            if (in_array($sortBy, $allowedSorts, true)) {
                $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
            }

            $perPage = min((int) $request->get('per_page', 20), 100);
            $episodes = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => PodcastEpisodeResource::collection($episodes)->resolve(),
                'meta' => [
                    'total' => $episodes->total(),
                    'per_page' => $episodes->perPage(),
                    'current_page' => $episodes->currentPage(),
                    'last_page' => $episodes->lastPage(),
                    'podcast' => [
                        'id' => $podcast->id,
                        'title' => $podcast->title,
                    ],
                ],
            ]);
        }, 'Failed to retrieve podcast episodes.');
    }

    /**
     * GET /api/admin/podcasts/categories
     *
     * List all podcast categories.
     */
    public function categories(): JsonResponse
    {
        return $this->handleApiAction(function () {
            $categories = PodcastCategory::withCount('podcasts')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $categories,
            ]);
        }, 'Failed to retrieve podcast categories.');
    }
}
