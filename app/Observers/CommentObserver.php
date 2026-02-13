<?php

namespace App\Observers;

use App\Models\Comment;
use App\Services\ActivityService;

class CommentObserver
{
    /**
     * Handle the Comment "created" event.
     */
    public function created(Comment $comment): void
    {
        // Log comment activity
        if ($comment->user && $comment->commentable) {
            $action = 'commented_' . strtolower(class_basename($comment->commentable_type));
            
            ActivityService::log(
                actor: $comment->user,
                action: $action,
                subject: $comment->commentable,
                metadata: [
                    'comment_excerpt' => substr($comment->comment, 0, 100),
                    'commentable_type' => class_basename($comment->commentable_type),
                    'commentable_title' => $comment->commentable->title ?? $comment->commentable->name ?? null,
                ]
            );
            
            // Increment comment count on the activity if it exists
            $activity = \App\Models\Activity::where('subject_type', get_class($comment->commentable))
                ->where('subject_id', $comment->commentable->id)
                ->latest()
                ->first();
                
            if ($activity) {
                ActivityService::incrementEngagement($activity, 'comments');
            }
        }
    }

    /**
     * Handle the Comment "deleted" event.
     */
    public function deleted(Comment $comment): void
    {
        // Decrement comment count on the activity if it exists
        $activity = \App\Models\Activity::where('subject_type', get_class($comment->commentable))
            ->where('subject_id', $comment->commentable->id)
            ->latest()
            ->first();
            
        if ($activity && $activity->comments_count > 0) {
            ActivityService::incrementEngagement($activity, 'comments', -1);
        }
    }
}
