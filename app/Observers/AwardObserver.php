<?php

namespace App\Observers;

use App\Models\Award;
use App\Services\ActivityService;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

class AwardObserver
{
    public function updated(Award $award): void
    {
        try {
            // Award season started (nominations open)
            if ($award->isDirty('status') && $award->status === Award::STATUS_NOMINATIONS_OPEN) {
                FeedItemService::create([
                    'type'           => 'award_season_started',
                    'module'         => 'awards',
                    'title'          => $award->title . ' ' . $award->year . ' — Nominations are now open!',
                    'body'           => $award->description ? substr($award->description, 0, 200) : null,
                    'actor_id'       => 0,
                    'actor_type'     => 'system',
                    'actor_name'     => 'TesoTunes Awards',
                    'subject_type'   => Award::class,
                    'subject_id'     => $award->id,
                    'media_type'     => 'image',
                    'media_url'      => $award->banner ?? $award->artwork ?? null,
                    'is_prestige'    => true,
                    'has_celebration' => true,
                    'actions'        => [
                        ['type' => 'view', 'label' => 'Nominate Now', 'url' => "/awards/{$award->slug}"],
                    ],
                    'extras'         => [
                        'year'               => $award->year,
                        'nominations_end_at' => $award->nomination_ends_at?->toIso8601String(),
                    ],
                ]);
            }

            // Voting opened
            if ($award->isDirty('status') && $award->status === Award::STATUS_VOTING_OPEN) {
                FeedItemService::create([
                    'type'           => 'award_season_started',
                    'module'         => 'awards',
                    'title'          => $award->title . ' ' . $award->year . ' — Voting is now open!',
                    'actor_id'       => 0,
                    'actor_type'     => 'system',
                    'actor_name'     => 'TesoTunes Awards',
                    'subject_type'   => Award::class,
                    'subject_id'     => $award->id,
                    'media_type'     => 'image',
                    'media_url'      => $award->banner ?? $award->artwork ?? null,
                    'is_prestige'    => true,
                    'actions'        => [
                        ['type' => 'vote', 'label' => 'Vote Now', 'url' => "/awards/{$award->slug}/vote"],
                    ],
                    'extras'         => [
                        'year'           => $award->year,
                        'voting_ends_at' => $award->voting_ends_at?->toIso8601String(),
                    ],
                ]);
            }

            // Award completed (winners announced)
            if ($award->isDirty('status') && $award->status === Award::STATUS_COMPLETED) {
                FeedItemService::create([
                    'type'            => 'award_won',
                    'module'          => 'awards',
                    'title'           => $award->title . ' ' . $award->year . ' — Winners announced!',
                    'actor_id'        => 0,
                    'actor_type'      => 'system',
                    'actor_name'      => 'TesoTunes Awards',
                    'subject_type'    => Award::class,
                    'subject_id'      => $award->id,
                    'media_type'      => 'image',
                    'media_url'       => $award->banner ?? $award->artwork ?? null,
                    'is_prestige'     => true,
                    'has_celebration' => true,
                    'actions'         => [
                        ['type' => 'view', 'label' => 'See Winners', 'url' => "/awards/{$award->slug}/winners"],
                    ],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('AwardObserver: Failed to create feed item', ['award_id' => $award->id, 'error' => $e->getMessage()]);
        }
    }
}
