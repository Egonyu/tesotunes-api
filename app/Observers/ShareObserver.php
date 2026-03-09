<?php

namespace App\Observers;

use App\Models\Share;
use App\Services\ActivityService;
use App\Services\FeedItemService;

class ShareObserver
{
    /**
     * Handle the Share "created" event.
     */
    public function created(Share $share): void
    {
        // Log share activity
        if ($share->user && $share->shareable) {
            $action = 'shared_'.strtolower(class_basename($share->shareable_type));

            ActivityService::log(
                actor: $share->user,
                action: $action,
                subject: $share->shareable,
                metadata: [
                    'shareable_type' => class_basename($share->shareable_type),
                    'shareable_title' => $share->shareable->title ?? $share->shareable->name ?? null,
                    'platform' => $share->platform ?? 'internal',
                ]
            );

            // Create feed item for shares
            FeedItemService::create([
                'type'          => 'shared_content',
                'module'        => 'social',
                'title'         => ($share->user->name ?? 'Someone') . ' shared "' . ($share->shareable->title ?? $share->shareable->name ?? 'content') . '"',
                'actor_id'      => $share->user->id,
                'actor_type'    => 'user',
                'actor_name'    => $share->user->name,
                'actor_avatar_url' => $share->user->avatar_url,
                'subject_type'  => get_class($share->shareable),
                'subject_id'    => $share->shareable->id,
                'extras'        => ['platform' => $share->platform ?? 'internal'],
            ]);

            // Increment share count on the activity if it exists
            $activity = \App\Models\Activity::where('subject_type', get_class($share->shareable))
                ->where('subject_id', $share->shareable->id)
                ->latest()
                ->first();

            if ($activity) {
                ActivityService::incrementEngagement($activity, 'shares');
            }
        }
    }

    /**
     * Handle the Share "deleted" event.
     */
    public function deleted(Share $share): void
    {
        // Decrement share count on the activity if it exists
        $activity = \App\Models\Activity::where('subject_type', get_class($share->shareable))
            ->where('subject_id', $share->shareable->id)
            ->latest()
            ->first();

        if ($activity && $activity->shares_count > 0) {
            ActivityService::incrementEngagement($activity, 'shares', -1);
        }
    }
}
