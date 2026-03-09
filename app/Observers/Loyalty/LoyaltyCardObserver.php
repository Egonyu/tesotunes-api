<?php

namespace App\Observers\Loyalty;

use App\Models\Loyalty\LoyaltyCard;
use Illuminate\Support\Str;

class LoyaltyCardObserver
{
    public function creating(LoyaltyCard $card): void
    {
        if (empty($card->uuid)) {
            $card->uuid = (string) Str::uuid();
        }

        if (empty($card->slug)) {
            $card->slug = Str::slug($card->name);
        }

        // Ensure unique slug
        $baseSlug = $card->slug;
        $counter = 1;
        while (LoyaltyCard::withTrashed()->where('slug', $card->slug)->exists()) {
            $card->slug = "{$baseSlug}-{$counter}";
            $counter++;
        }
    }

    public function created(LoyaltyCard $card): void
    {
        // Log activity (wrapped in try/catch since ActivityService may fail if activities table schema differs)
        try {
            if ($card->artist && $card->artist->user) {
                \App\Services\ActivityService::log(
                    actor: $card->artist->user,
                    action: 'created_loyalty_card',
                    subject: $card,
                    metadata: [
                        'card_name' => $card->name,
                        'artist_name' => $card->artist->stage_name ?? $card->artist->name,
                        'tiers' => array_keys($card->tiers ?? []),
                    ],
                    actorType: 'Artist'
                );

                \App\Services\FeedItemService::create([
                    'type'          => 'fan_club_joined',
                    'module'        => 'loyalty',
                    'title'         => ($card->artist->stage_name ?? $card->artist->name) . ' launched a fan club: ' . $card->name,
                    'actor_id'      => $card->artist->user->id,
                    'actor_type'    => 'artist',
                    'actor_name'    => $card->artist->stage_name ?? $card->artist->name,
                    'actor_avatar_url' => $card->artist->avatar_url,
                    'actor_verified'=> (bool) $card->artist->is_verified,
                    'subject_type'  => LoyaltyCard::class,
                    'subject_id'    => $card->id,
                    'actions'       => [
                        ['type' => 'view', 'label' => 'Join Fan Club', 'url' => "/loyalty/{$card->slug}"],
                    ],
                    'extras'        => [
                        'tiers' => array_keys($card->tiers ?? []),
                    ],
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to log loyalty card activity: {$e->getMessage()}");
        }
    }

    public function updated(LoyaltyCard $card): void
    {
        // If just published, log activity
        if ($card->isDirty('status') && $card->status === 'active' && $card->published_at) {
            try {
                if ($card->artist && $card->artist->user) {
                    \App\Services\ActivityService::log(
                        actor: $card->artist->user,
                        action: 'published_loyalty_card',
                        subject: $card,
                        metadata: [
                            'card_name' => $card->name,
                        ],
                        actorType: 'Artist'
                    );
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to log loyalty card publish activity: {$e->getMessage()}");
            }
        }
    }
}
