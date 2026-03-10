<?php

namespace App\Observers\Ojokotau;

use App\Models\CampaignPledge;
use App\Services\ActivityService;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

class CampaignPledgeObserver
{
    public function created(CampaignPledge $pledge): void
    {
        try {
            $user = $pledge->user;
            $campaign = $pledge->campaign;

            if (! $user || ! $campaign) {
                return;
            }

            ActivityService::log(
                actor: $user,
                action: 'pledged_campaign',
                subject: $pledge,
                metadata: [
                    'campaign_title' => $campaign->title,
                    'amount' => $pledge->amount,
                    'is_anonymous' => $pledge->is_anonymous,
                ]
            );

            // Respect anonymous pledge setting
            $actorName = $pledge->is_anonymous ? 'An anonymous supporter' : ($user->name ?? 'Someone');

            FeedItemService::create([
                'type' => 'campaign_funded',
                'module' => 'ojokotau',
                'title' => $actorName.' supported "'.$campaign->title.'" with UGX '.number_format($pledge->amount),
                'body' => $pledge->message ? substr($pledge->message, 0, 200) : null,
                'actor_id' => $pledge->is_anonymous ? 0 : $user->id,
                'actor_type' => $pledge->is_anonymous ? 'anonymous' : 'user',
                'actor_name' => $actorName,
                'actor_avatar_url' => $pledge->is_anonymous ? null : ($user->avatar_url ?? null),
                'subject_type' => CampaignPledge::class,
                'subject_id' => $pledge->id,
                'actions' => [
                    ['type' => 'view', 'label' => 'Support Campaign', 'url' => "/ojokotau/{$campaign->slug}"],
                ],
                'extras' => [
                    'campaign_id' => $campaign->id,
                    'campaign_title' => $campaign->title,
                    'amount' => $pledge->amount,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('CampaignPledgeObserver: Failed to create feed item', ['pledge_id' => $pledge->id, 'error' => $e->getMessage()]);
        }
    }
}
