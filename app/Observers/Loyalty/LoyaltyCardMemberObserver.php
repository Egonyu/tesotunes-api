<?php

namespace App\Observers\Loyalty;

use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\LoyaltyPoints;

class LoyaltyCardMemberObserver
{
    public function created(LoyaltyCardMember $member): void
    {
        // Ensure user has a loyalty_points record
        LoyaltyPoints::firstOrCreate(
            ['user_id' => $member->user_id],
            ['balance' => 0, 'lifetime_earned' => 0, 'lifetime_spent' => 0, 'current_multiplier' => 1.00]
        );
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
