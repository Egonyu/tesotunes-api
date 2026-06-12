<?php

namespace App\Observers;

use App\Models\Activity;
use App\Models\Modules\Forum\Poll;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

class PollObserver
{
    public function created(Poll $poll): void
    {
        try {
            Activity::create([
                'user_id' => $poll->user_id,
                'type' => 'created_poll',
                'subject_type' => 'App\Models\Modules\Forum\Poll',
                'subject_id' => $poll->id,
                'properties' => [
                    'poll_type' => $poll->poll_type,
                    'category' => $poll->category,
                    'is_multiple' => $poll->allow_multiple_votes,
                    'is_anonymous' => $poll->is_anonymous,
                    'ends_at' => $poll->ends_at?->toIso8601String(),
                ],
            ]);

            $user = $poll->user;
            FeedItemService::create([
                'type' => 'poll_created',
                'module' => 'forum',
                'title' => ($user->name ?? 'Someone').' created a poll: '.$poll->title,
                'actor_id' => $poll->user_id,
                'actor_type' => 'user',
                'actor_name' => $user->name ?? null,
                'actor_avatar_url' => $user->avatar_url ?? null,
                'subject_type' => Poll::class,
                'subject_id' => $poll->id,
                'actions' => [
                    ['type' => 'vote', 'label' => 'Vote Now', 'url' => "/polls/{$poll->id}"],
                ],
                'extras' => [
                    'poll_type' => $poll->poll_type,
                    'category' => $poll->category,
                    'credits_reward' => $poll->credits_reward,
                    'ends_at' => $poll->ends_at?->toIso8601String(),
                    'option_count' => $poll->options?->count() ?? 0,
                ],
            ]);

            $this->clearFeedCache($poll->user_id);
        } catch (\Exception $e) {
            Log::error('Failed to create activity for poll', [
                'poll_id' => $poll->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function updated(Poll $poll): void
    {
        if ($poll->isDirty('status') && $poll->status === 'closed') {
            try {
                Activity::create([
                    'user_id' => $poll->user_id,
                    'type' => 'closed_poll',
                    'subject_type' => 'App\Models\Modules\Forum\Poll',
                    'subject_id' => $poll->id,
                    'properties' => [
                        'total_votes' => $poll->votes()->count(),
                        'total_voters' => $poll->votes()->distinct('user_id')->count('user_id'),
                    ],
                ]);

                FeedItemService::create([
                    'type' => 'poll_ended',
                    'module' => 'forum',
                    'title' => 'Poll ended: '.$poll->title,
                    'actor_id' => $poll->user_id,
                    'actor_type' => 'user',
                    'actor_name' => $poll->user->name ?? null,
                    'actor_avatar_url' => $poll->user->avatar_url ?? null,
                    'subject_type' => Poll::class,
                    'subject_id' => $poll->id,
                    'actions' => [
                        ['type' => 'view', 'label' => 'View Results', 'url' => "/polls/{$poll->id}"],
                    ],
                    'extras' => [
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

    public function deleted(Poll $poll): void
    {
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

    protected function clearFeedCache(int $userId): void
    {
        try {
            \App\Helpers\CacheHelper::flush(['feed', "user:{$userId}"]);

            $followerIds = \DB::table('follows')
                ->where('followable_type', 'App\Models\User')
                ->where('followable_id', $userId)
                ->pluck('user_id');

            foreach ($followerIds as $followerId) {
                \App\Helpers\CacheHelper::flush(['feed', "user:{$followerId}"]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to clear feed cache', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
