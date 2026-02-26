<?php

namespace App\Traits;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Pluggable commenting for any Eloquent model.
 *
 * Usage:
 *   1. Add `use HasComments;` to your model.
 *   2. Ensure the model's table has a `comments_count` integer column
 *      (the Comment boot auto-increments/decrements it).
 *   3. Register the short type name in Comment::$commentableTypes
 *      so the universal API can resolve it.
 *
 * That's it — the model now supports:
 *   - GET  /api/comments/{type}/{id}
 *   - POST /api/comments  { commentable_type, commentable_id, content }
 *   - Replies, likes, threading, moderation
 */
trait HasComments
{
    /**
     * All top-level comments on this model (latest first).
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')
            ->whereNull('parent_id')
            ->latest();
    }

    /**
     * All comments including replies (flat).
     */
    public function allComments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Only approved top-level comments.
     */
    public function approvedComments(): MorphMany
    {
        return $this->comments()->where('status', 'approved');
    }

    /**
     * Check if a user has commented on this model.
     */
    public function hasCommentedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->allComments()->where('user_id', $user->id)->exists();
    }

    /**
     * Add a comment to this model.
     */
    public function addComment(User $user, string $content, ?int $parentId = null): Comment
    {
        return Comment::create([
            'user_id' => $user->id,
            'commentable_type' => static::class,
            'commentable_id' => $this->getKey(),
            'parent_id' => $parentId,
            'content' => $content,
            'status' => 'approved',
        ]);
    }
}
