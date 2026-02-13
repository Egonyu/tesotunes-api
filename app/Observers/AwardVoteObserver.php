<?php

namespace App\Observers;

use App\Models\AwardVote;
use App\Services\ActivityService;

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
            } catch (\Exception $e) {
                // Silently fail if activity logging fails
                // This prevents breaking the vote creation
            }
        }
    }
}
