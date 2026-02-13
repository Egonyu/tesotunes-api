<?php

namespace App\Observers;

use App\Models\Activity;
use App\Models\Modules\Forum\ForumTopic;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ForumTopicObserver
{
    /**
     * Handle the ForumTopic "created" event.
     */
    public function created(ForumTopic $topic): void
    {
        try {
            Activity::create([
                'actor_id' => $topic->user_id,
                'actor_type' => 'App\Models\User',
                'action' => 'created_forum_topic',
                'subject_type' => 'App\Models\Modules\Forum\ForumTopic',
                'subject_id' => $topic->id,
                'metadata' => [
                    'category_id' => $topic->category_id,
                    'category_name' => $topic->category->name ?? null,
                    'is_pinned' => $topic->is_pinned,
                    'is_featured' => $topic->is_featured,
                ],
            ]);

            // Clear feed cache for followers
            $this->clearFeedCache($topic->user_id);
        } catch (\Exception $e) {
            Log::error('Failed to create activity for forum topic', [
                'topic_id' => $topic->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the ForumTopic "updated" event.
     */
    public function updated(ForumTopic $topic): void
    {
        // If topic is marked as featured or pinned, create activity
        if ($topic->isDirty('is_featured') && $topic->is_featured) {
            try {
                Activity::create([
                    'actor_id' => 0, // System action
                    'actor_type' => 'System',
                    'action' => 'featured_forum_topic',
                    'subject_type' => 'App\Models\Modules\Forum\ForumTopic',
                    'subject_id' => $topic->id,
                    'metadata' => [
                        'category_id' => $topic->category_id,
                        'original_author_id' => $topic->user_id,
                    ],
                ]);

                $this->clearFeedCache($topic->user_id);
            } catch (\Exception $e) {
                Log::error('Failed to create featured topic activity', [
                    'topic_id' => $topic->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle the ForumTopic "deleted" event.
     */
    public function deleted(ForumTopic $topic): void
    {
        // Remove associated activities
        try {
            Activity::where('subject_type', 'App\Models\Modules\Forum\ForumTopic')
                ->where('subject_id', $topic->id)
                ->delete();

            $this->clearFeedCache($topic->user_id);
        } catch (\Exception $e) {
            Log::error('Failed to delete forum topic activities', [
                'topic_id' => $topic->id,
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
