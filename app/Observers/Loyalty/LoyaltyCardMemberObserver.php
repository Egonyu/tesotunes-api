<?php

namespace App\Observers\Loyalty;

use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\LoyaltyPoints;
use App\Services\FeedItemService;

class LoyaltyCardMemberObserver
{
    public function created(LoyaltyCardMember $member): void
    {
        // Ensure user has a loyalty_points record
        LoyaltyPoints::firstOrCreate(
            ['user_id' => $member->user_id],
            ['balance' => 0, 'lifetime_earned' => 0, 'lifetime_spent' => 0, 'current_multiplier' => 1.00]
        );

        // Create feed item for fan club joining
        try {
            $card = $member->loyaltyCard;
            $user = $member->user;
            if ($card && $user) {
                FeedItemService::create([
                    'type'          => 'fan_club_joined',
                    'module'        => 'loyalty',
                    'title'         => ($user->name ?? 'Someone') . ' joined ' . ($card->name ?? 'a fan club'),
                    'actor_id'      => $member->user_id,
                    'actor_type'    => 'user',
                    'actor_name'    => $user->name,
                    'actor_avatar_url' => $user->avatar_url,
                    'subject_type'  => LoyaltyCardMember::class,
                    'subject_id'    => $member->id,
                    'extras'        => [
                        'card_name' => $card->name,
                        'tier'      => $member->tier,
                    ],
                ]);
            }
        } catch (\Exception $e) {
            // Don't break member creation if feed item fails
        }
    }

    public function updated(LoyaltyCardMember $member): void
    {
        // If membership expired, refresh multiplier
        if ($member->isDirty('status') && in_array($member->status, ['expired', 'cancelled'])) {
            if (app()->bound(\App\Services\Loyalty\LoyaltyPointsService::class)) {
                app(\App\Services\Loyalty\LoyaltyPointsService::class)
                    ->refreshMultiplier($member->user);
            }
        }
    }

    public function deleted(LoyaltyCardMember $member): void
    {
        $card = $member->loyaltyCard;
        if ($card && $card->total_members > 0) {
            $card->decrement('total_members');
        }
    }
}
