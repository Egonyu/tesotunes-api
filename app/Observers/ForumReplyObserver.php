<?php

namespace App\Observers;

use App\Models\Activity;
use App\Models\Modules\Forum\ForumReply;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ForumReplyObserver
{
    /**
     * Handle the ForumReply "created" event.
     */
    public function created(ForumReply $reply): void
    {
        try {
            // Create activity for reply
            Activity::create([
                'user_id' => $reply->user_id,
                'type' => 'replied_forum_topic',
                'subject_type' => 'App\Models\Modules\Forum\ForumReply',
                'subject_id' => $reply->id,
                'properties' => [
                    'topic_id' => $reply->topic_id,
                    'topic_title' => $reply->topic->title ?? null,
                    'parent_id' => $reply->parent_id,
                    'is_nested' => $reply->parent_id !== null,
                ],
            ]);

            $user = $reply->user;
            FeedItemService::create([
                'type' => 'reply_posted',
                'module' => 'forum',
                'title' => ($user->name ?? 'Someone').' replied to "'.($reply->topic->title ?? 'a discussion').'"',
                'body' => $reply->content ? substr(strip_tags($reply->content), 0, 200) : null,
                'actor_id' => $reply->user_id,
                'actor_type' => 'user',
                'actor_name' => $user->name ?? null,
                'actor_avatar_url' => $user->avatar_url ?? null,
                'subject_type' => ForumReply::class,
                'subject_id' => $reply->id,
                'actions' => [
                    ['type' => 'view', 'label' => 'View Discussion', 'url' => "/forum/topics/{$reply->topic->slug}"],
                ],
                'extras' => [
                    'topic_id' => $reply->topic_id,
                    'topic_title' => $reply->topic->title ?? null,
                ],
            ]);

            // Clear feed cache
            $this->clearFeedCache($reply->user_id);
        } catch (\Exception $e) {
            Log::error('Failed to create activity for forum reply', [
                'reply_id' => $reply->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the ForumReply "updated" event.
     */
    public function updated(ForumReply $reply): void
    {
        // If reply is marked as solution, create activity
        if ($reply->isDirty('is_solution') && $reply->is_solution) {
            try {
                Activity::create([
                    'user_id' => $reply->topic->user_id, // Topic author marked it
                    'type' => 'marked_solution',
                    'subject_type' => 'App\Models\Modules\Forum\ForumReply',
                    'subject_id' => $reply->id,
                    'properties' => [
                        'topic_id' => $reply->topic_id,
                        'reply_author_id' => $reply->user_id,
                        'topic_title' => $reply->topic->title ?? null,
                    ],
                ]);

                // Clear cache for both topic author and reply author
                $this->clearFeedCache($reply->topic->user_id);
                $this->clearFeedCache($reply->user_id);
            } catch (\Exception $e) {
                Log::error('Failed to create solution marked activity', [
                    'reply_id' => $reply->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle the ForumReply "deleted" event.
     */
    public function deleted(ForumReply $reply): void
    {
        // Remove associated activities
        try {
            Activity::where('subject_type', 'App\Models\Modules\Forum\ForumReply')
                ->where('subject_id', $reply->id)
                ->delete();

            $this->clearFeedCache($reply->user_id);
        } catch (\Exception $e) {
            Log::error('Failed to delete forum reply activities', [
                'reply_id' => $reply->id,
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
