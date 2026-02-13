<?php

namespace App\Observers;

use App\Models\Like;
use App\Services\ActivityService;

class LikeObserver
{
    /**
     * Handle the Like "created" event.
     */
    public function created(Like $like): void
    {
        // Log activity when user likes something
        if ($like->user && $like->likeable) {
            $action = 'liked_' . strtolower(class_basename($like->likeable_type));
            
            ActivityService::log(
                actor: $like->user,
                action: $action,
                subject: $like->likeable,
                metadata: [
                    'likeable_type' => class_basename($like->likeable_type),
                    'likeable_title' => $like->likeable->title ?? $like->likeable->name ?? null,
                ]
            );
            
            // Increment like count on the activity if it exists
            $activity = \App\Models\Activity::where('subject_type', get_class($like->likeable))
                ->where('subject_id', $like->likeable->id)
                ->latest()
                ->first();
                
            if ($activity) {
                ActivityService::incrementEngagement($activity, 'likes');
            }
        }
    }

    /**
     * Handle the Like "updated" event.
     */
    public function updated(Like $like): void
    {
        //
    }

    /**
     * Handle the Like "deleted" event.
     */
    public function deleted(Like $like): void
    {
        // Decrement like count on the activity if it exists
        $activity = \App\Models\Activity::where('subject_type', get_class($like->likeable))
            ->where('subject_id', $like->likeable->id)
            ->latest()
            ->first();
            
        if ($activity && $activity->likes_count > 0) {
            ActivityService::incrementEngagement($activity, 'likes', -1);
        }
    }

    /**
     * Handle the Like "restored" event.
     */
    public function restored(Like $like): void
    {
        //
    }

    /**
     * Handle the Like "force deleted" event.
     */
    public function forceDeleted(Like $like): void
    {
        //
    }
}
