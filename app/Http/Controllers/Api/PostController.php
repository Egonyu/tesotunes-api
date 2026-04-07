<?php

namespace App\Http\Controllers\Api;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\PostMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostController extends Controller
{
    /**
     * GET /api/posts
     * List posts (public feed or user-specific).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Post::with(['user', 'media', 'song.artist'])
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());

        if ($user) {
            $query->where(function ($q) use ($user) {
                $q->where('visibility', 'public')
                    ->orWhere('user_id', $user->id)
                    ->orWhere(function ($q2) use ($user) {
                        $q2->where('visibility', 'followers')
                            ->whereIn('user_id', $user->following()->pluck('following_id'));
                    });
            });
        } else {
            $query->where('visibility', 'public');
        }

        $posts = $query->latest('published_at')
            ->paginate($request->integer('per_page', 20));

        $posts->through(fn (Post $post) => $this->transformPost($post, $user));

        return response()->json($posts);
    }

    /**
     * GET /api/posts/{post}
     * Show a single post with details.
     */
    public function show(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();

        $post->load(['user', 'media', 'song.artist']);
        $post->incrementViews();

        return response()->json([
            'data' => $this->transformPost($post, $user),
        ]);
    }

    /**
     * POST /api/posts
     * Create a new post with optional media.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'content' => 'required_without:media|string|max:5000',
            'visibility' => 'sometimes|in:public,followers,private',
            'song_id' => 'sometimes|nullable|exists:songs,id',
            'media' => 'sometimes|array|max:10',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,mp4,mov,webm|max:51200',
            'media_type' => 'sometimes|string|in:image,video,song,album',
            'media_id' => 'sometimes|integer',
            'media_url' => 'sometimes|string|url',
        ]);

        $user = $request->user();

        $post = DB::transaction(function () use ($request, $user) {
            $post = Post::create([
                'uuid' => Str::uuid(),
                'user_id' => $user->id,
                'content' => $request->input('content', ''),
                'type' => $this->determinePostType($request),
                'visibility' => $request->input('visibility', 'public'),
                'privacy' => $request->input('visibility', 'public'),
                'song_id' => $request->input('song_id'),
                'metadata' => $this->buildMetadata($request),
                'published_at' => now(),
            ]);

            // Handle file uploads
            if ($request->hasFile('media')) {
                foreach ($request->file('media') as $index => $file) {
                    $mimeType = $file->getMimeType();
                    $path = StorageHelper::store($file, 'posts/media');
                    $type = str_starts_with($mimeType, 'video/') ? 'video' : 'image';

                    PostMedia::create([
                        'post_id' => $post->id,
                        'type' => $type,
                        'url' => $path,
                        'thumbnail_url' => $type === 'image' ? $path : null,
                        'order' => $index,
                    ]);
                }
            }

            return $post;
        });

        $post->load(['user', 'media', 'song.artist']);

        return response()->json([
            'data' => $this->transformPost($post, $user),
            'message' => 'Post created successfully',
        ], 201);
    }

    /**
     * PUT /api/posts/{post}
     * Update own post.
     */
    public function update(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();

        if (! $post->canBeEditedBy($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'content' => 'sometimes|string|max:5000',
            'visibility' => 'sometimes|in:public,followers,private',
        ]);

        $post->update($request->only(['content', 'visibility']));
        $post->load(['user', 'media', 'song.artist']);

        return response()->json([
            'data' => $this->transformPost($post, $user),
            'message' => 'Post updated',
        ]);
    }

    /**
     * DELETE /api/posts/{post}
     * Delete own post.
     */
    public function destroy(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();

        if (! $post->canBeDeletedBy($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $post->delete();

        return response()->json(['message' => 'Post deleted']);
    }

    // ── Like / Unlike ────────────────────────────────────────────

    /**
     * POST /api/posts/{post}/like
     */
    public function like(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();

        $existing = PostLike::where('post_id', $post->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return response()->json([
                'data' => [
                    'liked' => true,
                    'likes_count' => $post->likes_count,
                ],
                'message' => 'Already liked',
            ]);
        }

        PostLike::create([
            'post_id' => $post->id,
            'user_id' => $user->id,
        ]);

        $post->increment('likes_count');

        return response()->json([
            'data' => [
                'liked' => true,
                'likes_count' => $post->fresh()->likes_count,
            ],
            'message' => 'Post liked',
        ]);
    }

    /**
     * DELETE /api/posts/{post}/like
     */
    public function unlike(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();

        $deleted = PostLike::where('post_id', $post->id)
            ->where('user_id', $user->id)
            ->delete();

        if ($deleted) {
            $post->decrement('likes_count');
        }

        return response()->json([
            'data' => [
                'liked' => false,
                'likes_count' => $post->fresh()->likes_count,
            ],
            'message' => 'Post unliked',
        ]);
    }

    // ── Bookmark ─────────────────────────────────────────────────

    /**
     * POST /api/posts/{post}/bookmark
     */
    public function bookmark(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();

        \App\Models\Like::firstOrCreate([
            'user_id' => $user->id,
            'likeable_type' => Post::class,
            'likeable_id' => $post->id,
            'type' => 'bookmark',
        ]);

        return response()->json([
            'data' => ['bookmarked' => true],
            'message' => 'Post bookmarked',
        ]);
    }

    /**
     * DELETE /api/posts/{post}/bookmark
     */
    public function unbookmark(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();

        \App\Models\Like::where('user_id', $user->id)
            ->where('likeable_type', Post::class)
            ->where('likeable_id', $post->id)
            ->where('type', 'bookmark')
            ->delete();

        return response()->json([
            'data' => ['bookmarked' => false],
            'message' => 'Bookmark removed',
        ]);
    }

    // ── Repost ───────────────────────────────────────────────────

    /**
     * POST /api/posts/{post}/repost
     */
    public function repost(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();
        $comment = $request->input('comment');

        $repost = $post->share($user, $comment);
        $repost->load(['user', 'media']);

        return response()->json([
            'data' => $this->transformPost($repost, $user),
            'message' => 'Reposted',
        ], 201);
    }

    // ── Comments ─────────────────────────────────────────────────

    /**
     * GET /api/posts/{post}/comments
     */
    public function comments(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();

        $comments = $post->comments()
            ->with(['user'])
            ->withCount('replies')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        $comments->through(fn (PostComment $comment) => $this->transformComment($comment, $user));

        return response()->json($comments);
    }

    /**
     * POST /api/posts/{post}/comments
     */
    public function storeComment(Request $request, Post $post): JsonResponse
    {
        $request->validate([
            'content' => 'required|string|max:2000',
            'parent_id' => 'sometimes|nullable|exists:post_comments,id',
        ]);

        $comment = $post->addComment(
            $request->user(),
            $request->input('content'),
            $request->input('parent_id'),
        );

        $comment->load('user');

        return response()->json([
            'data' => $this->transformComment($comment, $request->user()),
            'message' => 'Comment added',
        ], 201);
    }

    /**
     * DELETE /api/posts/{post}/comments/{comment}
     */
    public function destroyComment(Request $request, Post $post, PostComment $comment): JsonResponse
    {
        $user = $request->user();

        if ($comment->user_id !== $user->id && $post->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $comment->delete();
        $post->decrement('comments_count');

        return response()->json(['message' => 'Comment deleted']);
    }

    // ── Comment Like ─────────────────────────────────────────────

    /**
     * POST /api/comments/{comment}/like
     */
    public function likeComment(Request $request, PostComment $comment): JsonResponse
    {
        // Toggle using post_likes or a simple increment/decrement
        $user = $request->user();

        $existing = \App\Models\Like::where('user_id', $user->id)
            ->where('likeable_type', PostComment::class)
            ->where('likeable_id', $comment->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $comment->decrement('likes_count');
            $liked = false;
        } else {
            \App\Models\Like::create([
                'user_id' => $user->id,
                'likeable_type' => PostComment::class,
                'likeable_id' => $comment->id,
            ]);
            $comment->increment('likes_count');
            $liked = true;
        }

        return response()->json([
            'data' => [
                'liked' => $liked,
                'likes_count' => $comment->fresh()->likes_count,
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // TRANSFORMERS
    // ══════════════════════════════════════════════════════════════

    /**
     * Transform Post model to API response shape matching frontend types.
     */
    protected function transformPost(Post $post, ?\App\Models\User $viewer = null): array
    {
        $user = $post->user;

        return [
            'id' => $post->id,
            'uuid' => $post->uuid ?? Str::uuid()->toString(),
            'author' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username ?? $user->name,
                'avatar_url' => $user->avatar_url ?? $user->profile_photo_url ?? '',
                'is_verified' => (bool) ($user->is_verified ?? false),
            ],
            'content' => $post->content ?? '',
            'media' => $this->transformMedia($post),
            'visibility' => $post->visibility ?? 'public',
            'created_at' => $post->created_at?->toIso8601String(),
            'likes_count' => $post->likes_count ?? 0,
            'comments_count' => $post->comments_count ?? 0,
            'reposts_count' => $post->shares_count ?? 0,
            'views_count' => $post->views_count ?? 0,
            'is_liked' => $viewer ? $post->isLikedBy($viewer) : false,
            'is_reposted' => false,
            'is_bookmarked' => $viewer ? $this->isBookmarkedBy($post, $viewer) : false,
        ];
    }

    protected function transformMedia(Post $post): ?array
    {
        // Check if post has uploaded media
        $media = $post->media->first();
        if ($media) {
            return [
                'type' => $media->type,
                'url' => StorageHelper::url($media->url),
                'thumbnail_url' => StorageHelper::url($media->thumbnail_url),
            ];
        }

        // Check if post has a linked song
        if ($post->song) {
            return [
                'type' => 'song',
                'url' => $post->song->artwork_url,
                'thumbnail_url' => $post->song->artwork_url,
                'title' => $post->song->title,
                'artist' => $post->song->artist?->stage_name ?? $post->song->artist?->name ?? '',
                'song_id' => $post->song->id,
            ];
        }

        return null;
    }

    protected function transformComment(PostComment $comment, ?\App\Models\User $viewer = null): array
    {
        return [
            'id' => $comment->id,
            'author' => [
                'id' => $comment->user->id,
                'name' => $comment->user->name,
                'username' => $comment->user->username ?? $comment->user->name,
                'avatar_url' => $comment->user->avatar_url ?? $comment->user->profile_photo_url ?? '',
                'is_verified' => (bool) ($comment->user->is_verified ?? false),
            ],
            'content' => $comment->content,
            'created_at' => $comment->created_at?->toIso8601String(),
            'likes_count' => $comment->likes_count ?? 0,
            'is_liked' => $viewer ? \App\Models\Like::where('user_id', $viewer->id)
                ->where('likeable_type', PostComment::class)
                ->where('likeable_id', $comment->id)
                ->exists() : false,
            'replies_count' => $comment->replies_count ?? $comment->replies()->count(),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────

    protected function determinePostType(Request $request): string
    {
        if ($request->input('song_id')) {
            return 'music';
        }
        if ($request->hasFile('media')) {
            $first = $request->file('media')[0] ?? null;
            if ($first && str_starts_with($first->getMimeType(), 'video/')) {
                return 'video';
            }

            return 'image';
        }

        return 'text';
    }

    protected function buildMetadata(Request $request): ?array
    {
        $metadata = [];

        if ($request->input('media_type') && $request->input('media_id')) {
            $metadata['attached_type'] = $request->input('media_type');
            $metadata['attached_id'] = $request->input('media_id');
        }

        if ($request->input('media_url')) {
            $metadata['media_url'] = $request->input('media_url');
        }

        return ! empty($metadata) ? $metadata : null;
    }

    /**
     * GET /api/posts/{post}/likers — users who liked this post
     */
    public function likers(Request $request, Post $post): JsonResponse
    {
        $likers = PostLike::where('post_id', $post->id)
            ->with('user:id,name,username,avatar,email')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        $data = $likers->through(fn (PostLike $like) => [
            'id' => $like->user->id,
            'name' => $like->user->name,
            'username' => $like->user->username,
            'avatar_url' => $like->user->avatar
                ? StorageHelper::avatarUrl($like->user->avatar, $like->user->name)
                : StorageHelper::avatarUrl(null, $like->user->name),
            'is_verified' => (bool) ($like->user->is_verified ?? false),
        ]);

        return response()->json([
            'data' => $data->items(),
            'meta' => [
                'current_page' => $likers->currentPage(),
                'last_page' => $likers->lastPage(),
                'per_page' => $likers->perPage(),
                'total' => $likers->total(),
            ],
        ]);
    }

    protected function isBookmarkedBy(Post $post, \App\Models\User $user): bool
    {
        return \App\Models\Like::where('user_id', $user->id)
            ->where('likeable_type', Post::class)
            ->where('likeable_id', $post->id)
            ->where('type', 'bookmark')
            ->exists();
    }
}
