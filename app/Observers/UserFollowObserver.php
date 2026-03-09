<?php

namespace App\Observers;

use App\Models\UserFollow;
use App\Services\ActivityService;
use App\Services\FeedItemService;

class UserFollowObserver
{
    /**
     * Handle the UserFollow "created" event.
     */
    public function created(UserFollow $follow): void
    {
        // Log follow activity
        if ($follow->follower && $follow->followable) {
            $action = 'followed_'.strtolower(class_basename($follow->followable_type));

            ActivityService::log(
                actor: $follow->follower,
                action: $action,
                subject: $follow->followable,
                metadata: [
                    'followable_type' => class_basename($follow->followable_type),
                    'followable_name' => $follow->followable->name ?? $follow->followable->stage_name ?? null,
                ]
            );

            FeedItemService::create([
                'type'          => 'user_followed',
                'module'        => 'social',
                'title'         => ($follow->follower->name ?? 'Someone') . ' started following ' . ($follow->followable->name ?? $follow->followable->stage_name ?? 'someone'),
                'actor_id'      => $follow->follower->id,
                'actor_type'    => 'user',
                'actor_name'    => $follow->follower->name,
                'actor_avatar_url' => $follow->follower->avatar_url,
                'subject_type'  => $follow->followable_type,
                'subject_id'    => $follow->followable_id,
                'extras'        => [
                    'followable_name' => $follow->followable->name ?? $follow->followable->stage_name,
                ],
            ]);
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
