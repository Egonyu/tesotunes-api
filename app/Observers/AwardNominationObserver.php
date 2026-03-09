<?php

namespace App\Observers;

use App\Models\AwardNomination;
use App\Services\ActivityService;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

class AwardNominationObserver
{
    public function created(AwardNomination $nomination): void
    {
        try {
            $nominator = $nomination->nominatedBy;
            if (! $nominator) {
                return;
            }

            ActivityService::log(
                actor: $nominator,
                action: 'submitted_nomination',
                subject: $nomination,
                metadata: [
                    'award'        => $nomination->award->title ?? null,
                    'category'     => $nomination->category->name ?? null,
                    'nominee_name' => $nomination->nominee_name,
                ]
            );

            FeedItemService::create([
                'type'          => 'nomination_submitted',
                'module'        => 'awards',
                'title'         => ($nominator->name ?? 'Someone') . ' nominated ' . ($nomination->nominee_name ?? 'an artist') . ' for ' . ($nomination->category->name ?? 'an award'),
                'actor_id'      => $nominator->id,
                'actor_type'    => 'user',
                'actor_name'    => $nominator->name,
                'actor_avatar_url' => $nominator->avatar_url ?? null,
                'subject_type'  => AwardNomination::class,
                'subject_id'    => $nomination->id,
                'extras'        => [
                    'award_title'   => $nomination->award->title ?? null,
                    'category_name' => $nomination->category->name ?? null,
                    'nominee_name'  => $nomination->nominee_name,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('AwardNominationObserver: Failed to create feed item', ['nomination_id' => $nomination->id, 'error' => $e->getMessage()]);
        }
    }

    public function updated(AwardNomination $nomination): void
    {
        // When nomination is approved, boost visibility
        if ($nomination->isDirty('status') && $nomination->status === 'approved') {
            try {
                FeedItemService::create([
                    'type'          => 'nomination_submitted',
                    'module'        => 'awards',
                    'title'         => ($nomination->nominee_name ?? 'An artist') . ' has been officially nominated for ' . ($nomination->category->name ?? 'an award') . '!',
                    'actor_id'      => 0,
                    'actor_type'    => 'system',
                    'actor_name'    => 'TesoTunes Awards',
                    'subject_type'  => AwardNomination::class,
                    'subject_id'    => $nomination->id,
                    'media_type'    => 'image',
                    'media_url'     => $nomination->nominee_artwork ?? null,
                    'is_prestige'   => true,
                    'actions'       => [
                        ['type' => 'vote', 'label' => 'Vote', 'url' => "/awards/{$nomination->award->slug}/vote"],
                    ],
                ]);
            } catch (\Exception $e) {
                Log::error('AwardNominationObserver: Failed to create approval feed item', ['nomination_id' => $nomination->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
