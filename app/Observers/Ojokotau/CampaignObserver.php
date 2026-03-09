<?php

namespace App\Observers\Ojokotau;

use App\Models\Campaign;
use App\Services\ActivityService;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

class CampaignObserver
{
    public function created(Campaign $campaign): void
    {
        // Only create feed item when campaign is submitted/active
        if (in_array($campaign->status, ['active', 'submitted'])) {
            $this->createFeedItem($campaign);
        }
    }

    public function updated(Campaign $campaign): void
    {
        try {
            // Campaign activated/approved
            if ($campaign->isDirty('status') && $campaign->status === 'active') {
                $this->createFeedItem($campaign);
            }

            // Campaign featured
            if ($campaign->isDirty('is_featured') && $campaign->is_featured) {
                $user = $campaign->user;
                FeedItemService::create([
                    'type'            => 'campaign_milestone',
                    'module'          => 'ojokotau',
                    'title'           => 'Featured campaign: ' . $campaign->title,
                    'body'            => $campaign->description ? substr($campaign->description, 0, 200) : null,
                    'actor_id'        => 0,
                    'actor_type'      => 'system',
                    'actor_name'      => 'Ojokotau',
                    'subject_type'    => Campaign::class,
                    'subject_id'      => $campaign->id,
                    'is_prestige'     => true,
                    'has_celebration' => true,
                    'actions'         => [
                        ['type' => 'view', 'label' => 'Support Campaign', 'url' => "/ojokotau/{$campaign->slug}"],
                    ],
                ]);
            }

            // Campaign verified
            if ($campaign->isDirty('is_verified') && $campaign->is_verified) {
                FeedItemService::create([
                    'type'          => 'campaign_milestone',
                    'module'        => 'ojokotau',
                    'title'         => 'Campaign verified: ' . $campaign->title,
                    'actor_id'      => 0,
                    'actor_type'    => 'system',
                    'actor_name'    => 'Ojokotau',
                    'subject_type'  => Campaign::class,
                    'subject_id'    => $campaign->id,
                    'is_prestige'   => true,
                    'actions'       => [
                        ['type' => 'view', 'label' => 'Support Campaign', 'url' => "/ojokotau/{$campaign->slug}"],
                    ],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('CampaignObserver: Failed to create feed item', ['campaign_id' => $campaign->id, 'error' => $e->getMessage()]);
        }
    }

    protected function createFeedItem(Campaign $campaign): void
    {
        try {
            $user = $campaign->user;
            if (! $user) {
                return;
            }

            ActivityService::log(
                actor: $user,
                action: 'created_campaign',
                subject: $campaign,
                metadata: [
                    'title'         => $campaign->title,
                    'category'      => $campaign->category,
                    'target_amount' => $campaign->target_amount,
                ]
            );

            FeedItemService::create([
                'type'          => 'campaign_created',
                'module'        => 'ojokotau',
                'title'         => ($user->name ?? 'Someone') . ' started a campaign: ' . $campaign->title,
                'body'          => $campaign->description ? substr($campaign->description, 0, 200) : null,
                'actor_id'      => $user->id,
                'actor_type'    => 'user',
                'actor_name'    => $user->name,
                'actor_avatar_url' => $user->avatar_url ?? null,
                'subject_type'  => Campaign::class,
                'subject_id'    => $campaign->id,
                'actions'       => [
                    ['type' => 'view', 'label' => 'Support Campaign', 'url' => "/ojokotau/{$campaign->slug}"],
                ],
                'extras'        => [
                    'category'      => $campaign->category,
                    'target_amount' => $campaign->target_amount,
                    'urgency'       => $campaign->urgency,
                    'end_date'      => $campaign->end_date?->toDateString(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('CampaignObserver: Failed to create campaign feed item', ['campaign_id' => $campaign->id, 'error' => $e->getMessage()]);
        }
    }
}
