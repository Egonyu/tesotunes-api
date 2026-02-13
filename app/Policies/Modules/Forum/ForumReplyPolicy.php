<?php

namespace App\Policies\Modules\Forum;

use App\Models\User;
use App\Models\Modules\Forum\ForumReply;
use Illuminate\Auth\Access\HandlesAuthorization;

class ForumReplyPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if user can view reply
     */
    public function view(?User $user, ForumReply $reply): bool
    {
        // Anyone can view non-deleted replies
        return !$reply->trashed();
    }

    /**
     * Determine if user can update reply
     */
    public function update(User $user, ForumReply $reply): bool
    {
        // Owner can edit own reply or moderators can edit any
        return $user->id === $reply->user_id 
            || $user->hasAnyRole(['moderator', 'admin', 'super_admin']);
    }

    /**
     * Determine if user can delete reply
     */
    public function delete(User $user, ForumReply $reply): bool
    {
        // Owner can delete own reply or moderators can delete any
        return $user->id === $reply->user_id 
            || $user->hasAnyRole(['moderator', 'admin', 'super_admin']);
    }

    /**
     * Determine if user can restore reply
     */
    public function restore(User $user, ForumReply $reply): bool
    {
        // Only moderators and admins can restore
        return $user->hasAnyRole(['moderator', 'admin', 'super_admin']);
    }

    /**
     * Determine if user can permanently delete reply
     */
    public function forceDelete(User $user, ForumReply $reply): bool
    {
        // Only admins and super admins can permanently delete
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if user can highlight reply (moderator feature)
     */
    public function highlight(User $user, ForumReply $reply): bool
    {
        return $user->hasAnyRole(['moderator', 'admin', 'super_admin']);
    }

    /**
     * Determine if user can mark reply as solution
     */
    public function markAsSolution(User $user, ForumReply $reply): bool
    {
        // Topic owner or moderators can mark as solution
        return $user->id === $reply->topic->user_id 
            || $user->hasAnyRole(['moderator', 'admin', 'super_admin']);
    }
}
