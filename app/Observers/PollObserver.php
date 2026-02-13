<?php

namespace App\Observers;

use App\Models\Activity;
use App\Models\Modules\Forum\Poll;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PollObserver
{
    /**
     * Handle the Poll "created" event.
     */
    public function created(Poll $poll): void
    {
        try {
            Activity::create([
                'actor_id' => $poll->user_id,
                'actor_type' => 'App\Models\User',
                'action' => 'created_poll',
                'subject_type' => 'App\Models\Modules\Forum\Poll',
                'subject_id' => $poll->id,
                'metadata' => [
                    'pollable_type' => $poll->pollable_type,
                    'pollable_id' => $poll->pollable_id,
                    'is_multiple_choice' => $poll->allow_multiple_choices,
                    'is_anonymous' => $poll->anonymous_voting,
                    'ends_at' => $poll->ends_at?->toIso8601String(),
                ],
            ]);

            // Clear feed cache
            $this->clearFeedCache($poll->user_id);
        } catch (\Exception $e) {
            Log::error('Failed to create activity for poll', [
                'poll_id' => $poll->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the Poll "updated" event.
     */
    public function updated(Poll $poll): void
    {
        // If poll status changes to closed, create activity
        if ($poll->isDirty('status') && $poll->status === 'closed') {
            try {
                Activity::create([
                    'actor_id' => $poll->user_id,
                    'actor_type' => 'App\Models\User',
                    'action' => 'closed_poll',
                    'subject_type' => 'App\Models\Modules\Forum\Poll',
                    'subject_id' => $poll->id,
                    'metadata' => [
                        'total_votes' => $poll->votes()->count(),
                        'total_voters' => $poll->votes()->distinct('user_id')->count('user_id'),
                    ],
                ]);

                $this->clearFeedCache($poll->user_id);
            } catch (\Exception $e) {
                Log::error('Failed to create closed poll activity', [
                    'poll_id' => $poll->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle the Poll "deleted" event.
     */
    public function deleted(Poll $poll): void
    {
        // Remove associated activities
        try {
            Activity::where('subject_type', 'App\Models\Modules\Forum\Poll')
                ->where('subject_id', $poll->id)
                ->delete();

            $this->clearFeedCache($poll->user_id);
        } catch (\Exception $e) {
            Log::error('Failed to delete poll activities', [
                'poll_id' => $poll->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear feed cache for user and followers
     */
    protected function clearFeedCache(int $userId): void
    {
        try {
            \App\Helpers\CacheHelper::flush(['feed', "user:{$userId}"]);
            
            // Also clear cache for users following this user
            $followerIds = \DB::table('follows')
                ->where('followable_type', 'App\Models\User')
                ->where('followable_id', $userId)
                ->pluck('user_id');

            foreach ($followerIds as $followerId) {
                \App\Helpers\CacheHelper::flush(['feed', "user:{$followerId}"]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to clear feed cache', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
