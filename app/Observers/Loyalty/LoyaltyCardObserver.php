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
                        'tiers'  => array_keys($card->tiers ?? []),
                    ],
                    actorType: 'Artist'
                );
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
