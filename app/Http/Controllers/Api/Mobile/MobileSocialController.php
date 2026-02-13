<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\User;
use App\Models\UserFollow;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class MobileSocialController extends Controller
{
    /**
     * Get social feed for mobile app
     */
    public function getFeed(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Get posts from followed users + own posts
        $followingIds = UserFollow::where('follower_id', $user->id)
            ->pluck('following_id')
            ->toArray();
        
        $followingIds[] = $user->id; // Include own posts
        
        $posts = Post::whereIn('user_id', $followingIds)
            ->orWhere('visibility', 'public')
            ->with([
                'user:id,name,avatar',
                'song:id,title,artist_id,artwork',
                'song.artist:id,stage_name',
                'comments.user:id,name,avatar',
                'likes.user:id,name'
            ])
            ->withCount(['likes', 'comments'])
            ->latest()
            ->paginate(20);
        
        return response()->json([
            'success' => true,
            'posts' => $posts->map(fn($post) => $this->formatPost($post, $user)),
            'pagination' => [
                'current_page' => $posts->currentPage(),
                'total_pages' => $posts->lastPage(),
                'total' => $posts->total(),
                'per_page' => $posts->perPage(),
            ],
        ]);
    }
    
    /**
     * Get user's own posts
     */
    public function getMyPosts(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $posts = Post::where('user_id', $user->id)
            ->with([
                'song:id,title,artist_id,artwork',
                'song.artist:id,stage_name',
            ])
            ->withCount(['likes', 'comments'])
            ->latest()
            ->paginate(20);
        
        return response()->json([
            'success' => true,
            'posts' => $posts->map(fn($post) => $this->formatPost($post, $user)),
            'pagination' => [
                'current_page' => $posts->currentPage(),
                'total_pages' => $posts->lastPage(),
                'total' => $posts->total(),
                'per_page' => $posts->perPage(),
            ],
        ]);
    }
    
    /**
     * Create a new post
     */
    public function createPost(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:5000',
            'song_id' => 'nullable|integer|exists:songs,id',
            'media' => 'nullable|array|max:4',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,mp4|max:10240',
            'visibility' => 'nullable|in:public,followers,private',
        ]);
        
        $user = $request->user();
        
        try {
            $post = Post::create([
                'user_id' => $user->id,
                'content' => $validated['content'],
                'song_id' => $validated['song_id'] ?? null,
                'visibility' => $validated['visibility'] ?? 'public',
            ]);
            
            // Handle media uploads
            if ($request->hasFile('media')) {
                $mediaUrls = [];
                foreach ($request->file('media') as $file) {
                    $path = $file->store('posts/' . $user->id, 'digitalocean');
                    $mediaUrls[] = [
                        'url' => Storage::disk('digitalocean')->url($path),
                        'type' => str_starts_with($file->getMimeType(), 'video') ? 'video' : 'image',
                    ];
                }
                $post->update(['media' => $mediaUrls]);
            }
            
            $post->load([
                'song:id,title,artist_id,artwork',
                'song.artist:id,stage_name',
            ]);
            
            return response()->json([
                'success' => true,
                'post' => $this->formatPost($post->fresh(), $user),
                'message' => 'Post created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create post',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Update a post
     */
    public function updatePost(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();
        
        if ($post->user_id !== $user->id) {
            return response()->json([
                'error' => 'Unauthorized'
            ], 403);
        }
        
        $validated = $request->validate([
            'content' => 'nullable|string|max:5000',
            'visibility' => 'nullable|in:public,followers,private',
        ]);
        
        $post->update($validated);
        
        return response()->json([
            'success' => true,
            'post' => $this->formatPost($post->fresh(), $user),
            'message' => 'Post updated successfully',
        ]);
    }
    
    /**
     * Delete a post
     */
    public function deletePost(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();
        
        if ($post->user_id !== $user->id) {
            return response()->json([
                'error' => 'Unauthorized'
            ], 403);
        }
        
        $post->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Post deleted successfully',
        ]);
    }
    
    /**
     * Like/unlike a post
     */
    public function toggleLike(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();
        
        $like = PostLike::where('post_id', $post->id)
            ->where('user_id', $user->id)
            ->first();
        
        if ($like) {
            $like->delete();
            $action = 'unliked';
        } else {
            PostLike::create([
                'post_id' => $post->id,
                'user_id' => $user->id,
            ]);
            $action = 'liked';
            
            // Create notification for post owner
            if ($post->user_id !== $user->id) {
                Notification::create([
                    'user_id' => $post->user_id,
                    'notification_type' => 'post_like',
                    'actor_id' => $user->id,
                    'notifiable_type' => Post::class,
                    'notifiable_id' => $post->id,
                    'title' => 'New Like on Your Post',
                    'message' => "{$user->name} liked your post",
                    'action_url' => "/posts/{$post->id}",
                ]);
            }
        }
        
        $likesCount = PostLike::where('post_id', $post->id)->count();
        
        return response()->json([
            'success' => true,
            'action' => $action,
            'likes_count' => $likesCount,
        ]);
    }
    
    /**
     * Get post comments
     */
    public function getComments(Request $request, Post $post): JsonResponse
    {
        $comments = PostComment::where('post_id', $post->id)
            ->with('user:id,name,avatar')
            ->latest()
            ->paginate(50);
        
        return response()->json([
            'success' => true,
            'comments' => $comments->map(fn($comment) => [
                'id' => $comment->id,
                'content' => $comment->content,
                'user' => [
                    'id' => $comment->user->id,
                    'name' => $comment->user->name,
                    'avatar' => $comment->user->avatar,
                ],
                'created_at' => $comment->created_at->toISOString(),
            ]),
            'pagination' => [
                'current_page' => $comments->currentPage(),
                'total_pages' => $comments->lastPage(),
                'total' => $comments->total(),
            ],
        ]);
    }
    
    /**
     * Add comment to post
     */
    public function addComment(Request $request, Post $post): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:2000',
        ]);
        
        $user = $request->user();
        
        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'content' => $validated['content'],
        ]);
        
        // Create notification for post owner
        if ($post->user_id !== $user->id) {
            Notification::create([
                'user_id' => $post->user_id,
                'notification_type' => 'post_comment',
                'actor_id' => $user->id,
                'notifiable_type' => Post::class,
                'notifiable_id' => $post->id,
                'title' => 'New Comment on Your Post',
                'message' => "{$user->name} commented: " . substr($validated['content'], 0, 100),
                'action_url' => "/posts/{$post->id}",
            ]);
        }
        
        return response()->json([
            'success' => true,
            'comment' => [
                'id' => $comment->id,
                'content' => $comment->content,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar,
                ],
                'created_at' => $comment->created_at->toISOString(),
            ],
            'message' => 'Comment added successfully',
        ], 201);
    }
    
    /**
     * Delete a comment
     */
    public function deleteComment(Request $request, Post $post, PostComment $comment): JsonResponse
    {
        $user = $request->user();
        
        // User can delete own comments or comments on their posts
        if ($comment->user_id !== $user->id && $post->user_id !== $user->id) {
            return response()->json([
                'error' => 'Unauthorized'
            ], 403);
        }
        
        $comment->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Comment deleted successfully',
        ]);
    }
    
    /**
     * Get notifications
     */
    public function getNotifications(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $notifications = Notification::where('user_id', $user->id)
            ->latest()
            ->paginate(50);
        
        return response()->json([
            'success' => true,
            'notifications' => $notifications->map(fn($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'data' => $n->data,
                'read' => (bool) $n->read_at,
                'created_at' => $n->created_at->toISOString(),
            ]),
            'unread_count' => Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->count(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'total_pages' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }
    
    /**
     * Mark notification as read
     */
    public function markNotificationRead(Request $request, Notification $notification): JsonResponse
    {
        $user = $request->user();
        
        if ($notification->user_id !== $user->id) {
            return response()->json([
                'error' => 'Unauthorized'
            ], 403);
        }
        
        $notification->update(['read_at' => now()]);
        
        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
        ]);
    }
    
    /**
     * Mark all notifications as read
     */
    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        
        Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        
        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
        ]);
    }
    
    /**
     * Format post for API response
     */
    private function formatPost(Post $post, User $currentUser): array
    {
        $isLiked = PostLike::where('post_id', $post->id)
            ->where('user_id', $currentUser->id)
            ->exists();
        
        return [
            'id' => $post->id,
            'content' => $post->content,
            'media' => $post->media ?? [],
            'visibility' => $post->visibility,
            'user' => [
                'id' => $post->user->id,
                'name' => $post->user->name,
                'avatar' => $post->user->avatar,
            ],
            'song' => $post->song ? [
                'id' => $post->song->id,
                'title' => $post->song->title,
                'artist' => $post->song->artist->stage_name ?? 'Unknown',
                'artwork' => $post->song->artwork ? Storage::disk('digitalocean')->url($post->song->artwork) : null,
            ] : null,
            'likes_count' => $post->likes_count ?? 0,
            'comments_count' => $post->comments_count ?? 0,
            'is_liked' => $isLiked,
            'created_at' => $post->created_at->toISOString(),
            'updated_at' => $post->updated_at->toISOString(),
        ];
    }
}
