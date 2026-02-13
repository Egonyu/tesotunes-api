<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ForumThreadResource;
use App\Http\Resources\ForumReplyResource;
use App\Models\Modules\Forum\ForumCategory;
use App\Models\Modules\Forum\ForumReply;
use App\Models\Modules\Forum\ForumTopic;
use Illuminate\Http\Request;

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
                'active_topics' => ForumTopic::where('status', 'active')->count(),
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
                    $sub->where('title', 'like', '%' . $request->search . '%')
                        ->orWhere('content', 'like', '%' . $request->search . '%');
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
     * DELETE /api/admin/forums/{id}
     */
    public function destroy(int $id)
    {
        ForumTopic::findOrFail($id)->delete();

        return response()->json(['message' => 'Topic deleted successfully']);
    }

    /**
     * POST /api/admin/forums/{id}/pin
     */
    public function togglePin(int $id)
    {
        $topic = ForumTopic::findOrFail($id);
        $topic->is_pinned = !$topic->is_pinned;
        $topic->save();

        return response()->json([
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
        $topic->is_locked = !$topic->is_locked;
        $topic->save();

        return response()->json([
            'message' => $topic->is_locked ? 'Topic locked' : 'Topic unlocked',
            'is_locked' => $topic->is_locked,
        ]);
    }

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
