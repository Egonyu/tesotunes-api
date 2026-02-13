<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Like;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class CommentController extends Controller
{
    public function index(Request $request, string $commentableType, int $commentableId): JsonResponse
    {
        try {
            $modelClass = 'App\\Models\\' . ucfirst($commentableType);

            if (!class_exists($modelClass)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid commentable type'
                ], 400);
            }

            $commentable = $modelClass::findOrFail($commentableId);

            $comments = Comment::where('commentable_type', $modelClass)
                ->where('commentable_id', $commentableId)
                ->approved()
                ->topLevel()
                ->with(['user', 'replies.user'])
                ->ordered()
                ->paginate($request->get('per_page', 20));

            // Add user-specific data
            if (auth()->check()) {
                $comments->getCollection()->each(function ($comment) {
                    $comment->is_liked = $comment->likes()
                        ->where('user_id', auth()->id())
                        ->exists();
                    $comment->can_edit = $comment->canBeEditedBy(auth()->user());
                    $comment->can_delete = $comment->canBeDeletedBy(auth()->user());

                    // Add same data for replies
                    $comment->replies->each(function ($reply) {
                        $reply->is_liked = $reply->likes()
                            ->where('user_id', auth()->id())
                            ->exists();
                        $reply->can_edit = $reply->canBeEditedBy(auth()->user());
                        $reply->can_delete = $reply->canBeDeletedBy(auth()->user());
                    });
                });
            }

            return response()->json([
                'success' => true,
                'data' => $comments
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch comments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'commentable_type' => 'required|string',
                'commentable_id' => 'required|integer',
                'content' => 'required|string|max:1000',
                'parent_id' => 'nullable|integer|exists:comments,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $modelClass = 'App\\Models\\' . ucfirst($request->commentable_type);

            if (!class_exists($modelClass)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid commentable type'
                ], 400);
            }

            $commentable = $modelClass::findOrFail($request->commentable_id);
            $user = auth()->user();

            $comment = Comment::create([
                'user_id' => $user->id,
                'commentable_type' => $modelClass,
                'commentable_id' => $commentable->id,
                'parent_id' => $request->parent_id,
                'content' => $request->content,
                'status' => 'approved', // Auto-approve for now, add moderation later
            ]);

            // If it's a reply, increment parent reply count and notify
            if ($request->parent_id) {
                $parentComment = Comment::find($request->parent_id);
                $parentComment->increment('reply_count');

                // Notify parent comment author
                if ($parentComment->user_id !== $user->id) {
                    $parentComment->user->notifications()->create([
                        'type' => 'comment_reply',
                        'title' => 'New Reply',
                        'message' => "{$user->name} replied to your comment",
                        'data' => [
                            'comment_id' => $parentComment->id,
                            'reply_id' => $comment->id,
                            'commentable_type' => $modelClass,
                            'commentable_id' => $commentable->id,
                        ],
                    ]);
                }
            } else {
                // Notify content owner if it's a top-level comment
                if (method_exists($commentable, 'user') && $commentable->user && $commentable->user->id !== $user->id) {
                    $commentable->user->notifications()->create([
                        'type' => 'new_comment',
                        'title' => 'New Comment',
                        'message' => "{$user->name} commented on your " . class_basename($commentable),
                        'data' => [
                            'comment_id' => $comment->id,
                            'commentable_type' => $modelClass,
                            'commentable_id' => $commentable->id,
                        ],
                    ]);
                }
            }

            // Create activity
            $user->activities()->create([
                'type' => 'commented_on_' . strtolower(class_basename($commentable)),
                'activityable_type' => $modelClass,
                'activityable_id' => $commentable->id,
                'data' => [
                    'comment_id' => $comment->id,
                    'content_preview' => substr($request->content, 0, 100),
                ]
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Comment added successfully',
                'data' => $comment->load('user')
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add comment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Comment $comment): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$comment->canBeEditedBy($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to edit this comment'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'content' => 'required|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $comment->update([
                'content' => $request->content,
                'status' => 'approved' // Reset to approved after edit
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Comment updated successfully',
                'data' => $comment->fresh()->load('user')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update comment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Comment $comment): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$comment->canBeDeletedBy($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to delete this comment'
                ], 403);
            }

            // If it's a reply, decrement parent reply count
            if ($comment->parent_id) {
                $parentComment = Comment::find($comment->parent_id);
                $parentComment?->decrement('reply_count');
            }

            $comment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Comment deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete comment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function toggleLike(Comment $comment): JsonResponse
    {
        try {
            $user = auth()->user();
            $isLiked = Like::toggle($user, $comment);

            return response()->json([
                'success' => true,
                'message' => $isLiked ? 'Comment liked' : 'Comment unliked',
                'is_liked' => $isLiked,
                'like_count' => $comment->fresh()->like_count
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle like',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function reply(Request $request, Comment $comment): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'content' => 'required|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = auth()->user();
            $reply = $comment->addReply($user, $request->content);

            return response()->json([
                'success' => true,
                'message' => 'Reply added successfully',
                'data' => $reply->load('user')
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add reply',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}