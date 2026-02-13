<?php

namespace App\Observers;

use App\Models\UserFollow;
use App\Services\ActivityService;

class UserFollowObserver
{
    /**
     * Handle the UserFollow "created" event.
     */
    public function created(UserFollow $follow): void
    {
        // Log follow activity
        if ($follow->follower && $follow->followable) {
            $action = 'followed_' . strtolower(class_basename($follow->followable_type));
            
            ActivityService::log(
                actor: $follow->follower,
                action: $action,
                subject: $follow->followable,
                metadata: [
                    'followable_type' => class_basename($follow->followable_type),
                    'followable_name' => $follow->followable->name ?? $follow->followable->stage_name ?? null,
                ]
            );
        }
    }

    /**
     * Handle the UserFollow "deleted" event (unfollow).
     */
    public function deleted(UserFollow $follow): void
    {
        // Optionally log unfollow activity
        // Usually we don't want to show unfollows in feed
    }
}
