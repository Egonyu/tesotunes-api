<?php

namespace App\Policies\Modules\Forum;

use App\Models\Modules\Forum\ForumTopic;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ForumTopicPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(?User $user): bool
    {
        // Anyone can view topics if forum module is enabled
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(?User $user, ForumTopic $forumTopic): bool
    {
        // Anyone can view active topics
        return $forumTopic->status === 'active';
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Check minimum reputation if configured
        $config = \App\Models\ModuleSetting::getConfiguration('forum');
        $minReputation = $config['min_reputation_to_post'] ?? 0;
        
        // For now, all authenticated users can create topics
        // TODO: Implement reputation system
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ForumTopic $forumTopic): bool
    {
        // Users can edit their own topics, moderators can edit any
        return $user->id === $forumTopic->user_id || 
               $user->hasAnyRole(['admin', 'super_admin', 'moderator']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ForumTopic $forumTopic): bool
    {
        // Users can delete their own topics (if no replies), moderators can delete any
        if ($user->hasAnyRole(['admin', 'super_admin', 'moderator'])) {
            return true;
        }

        return $user->id === $forumTopic->user_id && $forumTopic->replies_count === 0;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ForumTopic $forumTopic): bool
    {
        // Only moderators can restore
        return $user->hasAnyRole(['admin', 'super_admin', 'moderator']);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ForumTopic $forumTopic): bool
    {
        // Only admins can permanently delete
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine whether the user can moderate topics
     */
    public function moderate(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin', 'moderator']);
    }

    /**
     * Determine whether the user can pin topics
     */
    public function pin(User $user, ForumTopic $forumTopic): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin', 'moderator']);
    }

    /**
     * Determine whether the user can lock topics
     */
    public function lock(User $user, ForumTopic $forumTopic): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin', 'moderator']);
    }

    /**
     * Determine whether the user can feature topics
     */
    public function feature(User $user, ForumTopic $forumTopic): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin', 'moderator']);
    }

    /**
     * Determine whether the user can reply to a topic
     */
    public function reply(User $user, ForumTopic $forumTopic): bool
    {
        // Can't reply to locked or closed topics
        if ($forumTopic->is_locked || $forumTopic->status !== 'active') {
            return false;
        }

        return true;
    }
}
