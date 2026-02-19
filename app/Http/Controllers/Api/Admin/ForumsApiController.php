<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ForumReplyResource;
use App\Http\Resources\ForumThreadResource;
use App\Models\Modules\Forum\ForumCategory;
use App\Models\Modules\Forum\ForumReply;
use App\Models\Modules\Forum\ForumTopic;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ForumsApiController extends Controller
{
    /**
     * GET /api/admin/forums/stats
     */
    public function stats()
    {
        return response()->json([
            'data' => [
                'total_topics' => ForumTopic::count(),
                'total_replies' => ForumReply::count(),
                'active_topics' => ForumTopic::where('status', 'published')->count(),
                'total_categories' => ForumCategory::active()->count(),
                'recent_topics_30d' => ForumTopic::where('created_at', '>=', now()->subDays(30))->count(),
                'recent_replies_30d' => ForumReply::where('created_at', '>=', now()->subDays(30))->count(),
            ],
        ]);
    }

    /**
     * GET /api/admin/forums
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 10), 100);

        $topics = ForumTopic::with(['user', 'category'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->where('title', 'like', '%'.$request->search.'%')
                        ->orWhere('content', 'like', '%'.$request->search.'%');
                });
            })
            ->when($request->filled('category') && $request->category !== 'all', fn ($q) => $q->where('category_id', $request->category))
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('last_activity_at')
            ->paginate($perPage);

        return ForumThreadResource::collection($topics);
    }

    /**
     * GET /api/admin/forums/{id}
     */
    public function show(int $id)
    {
        $topic = ForumTopic::with(['user', 'category'])->findOrFail($id);

        return new ForumThreadResource($topic);
    }

    /**
     * POST /api/admin/forums
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'category_id' => 'required|exists:forum_categories,id',
            'is_pinned' => 'boolean',
            'is_featured' => 'boolean',
            'status' => 'in:published,draft,hidden',
        ]);

        $topic = ForumTopic::create([
            ...$validated,
            'user_id' => $request->user()->id,
            'slug' => Str::slug($validated['title']).'-'.Str::random(6),
            'status' => $validated['status'] ?? 'published',
            'last_activity_at' => now(),
        ]);

        // Increment category topic count
        $topic->category->increment('topics_count');

        return response()->json([
            'success' => true,
            'message' => 'Topic created successfully',
            'data' => new ForumThreadResource($topic->load(['user', 'category'])),
        ], 201);
    }

    /**
     * PUT /api/admin/forums/{id}
     */
    public function update(Request $request, int $id)
    {
        $topic = ForumTopic::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'category_id' => 'sometimes|exists:forum_categories,id',
            'is_pinned' => 'boolean',
            'is_featured' => 'boolean',
            'status' => 'in:published,draft,hidden',
        ]);

        $topic->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Topic updated successfully',
            'data' => new ForumThreadResource($topic->fresh(['user', 'category'])),
        ]);
    }

    /**
     * DELETE /api/admin/forums/{id}
     */
    public function destroy(int $id)
    {
        $topic = ForumTopic::findOrFail($id);
        $topic->category->decrement('topics_count');
        $topic->delete();

        return response()->json(['success' => true, 'message' => 'Topic deleted successfully']);
    }

    /**
     * POST /api/admin/forums/{id}/pin
     */
    public function togglePin(int $id)
    {
        $topic = ForumTopic::findOrFail($id);
        $topic->is_pinned = ! $topic->is_pinned;
        $topic->save();

        return response()->json([
            'success' => true,
            'message' => $topic->is_pinned ? 'Topic pinned' : 'Topic unpinned',
            'is_pinned' => $topic->is_pinned,
        ]);
    }

    /**
     * POST /api/admin/forums/{id}/lock
     */
    public function toggleLock(int $id)
    {
        $topic = ForumTopic::findOrFail($id);
        $topic->is_locked = ! $topic->is_locked;
        $topic->save();

        return response()->json([
            'success' => true,
            'message' => $topic->is_locked ? 'Topic locked' : 'Topic unlocked',
            'is_locked' => $topic->is_locked,
        ]);
    }

    // ── Category CRUD ──────────────────────────────────────────

    /**
     * GET /api/admin/forums/categories
     */
    public function categories()
    {
        $categories = ForumCategory::orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    /**
     * POST /api/admin/forums/categories
     */
    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:20',
            'sort_order' => 'integer|min:0',
            'is_active' => 'boolean',
        ]);

        $category = ForumCategory::create([
            ...$validated,
            'slug' => Str::slug($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => $category,
        ], 201);
    }

    /**
     * GET /api/admin/forums/categories/{id}
     */
    public function showCategory(int $id)
    {
        $category = ForumCategory::findOrFail($id);

        return response()->json(['data' => $category]);
    }

    /**
     * PUT /api/admin/forums/categories/{id}
     */
    public function updateCategory(Request $request, int $id)
    {
        $category = ForumCategory::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:20',
            'sort_order' => 'integer|min:0',
            'is_active' => 'boolean',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => $category->fresh(),
        ]);
    }

    /**
     * DELETE /api/admin/forums/categories/{id}
     */
    public function destroyCategory(int $id)
    {
        $category = ForumCategory::findOrFail($id);

        if ($category->topics()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category with existing topics. Move or delete topics first.',
            ], 422);
        }

        $category->delete();

        return response()->json(['success' => true, 'message' => 'Category deleted successfully']);
    }

    /**
     * GET /api/admin/forums/{id}/replies
     */
    public function replies(int $id, Request $request)
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        $topic = ForumTopic::findOrFail($id);

        $replies = $topic->replies()
            ->with('user')
            ->orderBy('created_at')
            ->paginate($perPage);

        return ForumReplyResource::collection($replies);
    }
}
