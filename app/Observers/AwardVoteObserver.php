<?php

namespace App\Observers;

use App\Models\AwardVote;
use App\Services\ActivityService;
use App\Services\FeedItemService;

class AwardVoteObserver
{
    /**
     * Handle the AwardVote "created" event.
     */
    public function created(AwardVote $vote): void
    {
        // Log award voting activity
        if ($vote->user_id && $vote->nomination) {
            try {
                ActivityService::log(
                    actor: $vote->user,
                    action: 'voted_award',
                    subject: $vote->nomination,
                    metadata: [
                        'award' => $vote->award->title ?? null,
                        'category' => $vote->category->name ?? null,
                        'nominee' => $vote->nomination->nominee_name ?? null,
                    ]
                );

                FeedItemService::create([
                    'type'          => 'award_voted',
                    'module'        => 'awards',
                    'title'         => ($vote->user->name ?? 'Someone') . ' voted for ' . ($vote->nomination->nominee_name ?? 'a nominee') . ' in ' . ($vote->award->title ?? 'an award'),
                    'actor_id'      => $vote->user_id,
                    'actor_type'    => 'user',
                    'actor_name'    => $vote->user->name,
                    'actor_avatar_url' => $vote->user->avatar_url,
                    'subject_type'  => get_class($vote->nomination),
                    'subject_id'    => $vote->nomination->id,
                    'extras'        => [
                        'award_title'   => $vote->award->title ?? null,
                        'category_name' => $vote->category->name ?? null,
                        'nominee_name'  => $vote->nomination->nominee_name ?? null,
                    ],
                ]);
            } catch (\Exception $e) {
                // Silently fail if activity logging fails
                // This prevents breaking the vote creation
            }
        }
    }
}
