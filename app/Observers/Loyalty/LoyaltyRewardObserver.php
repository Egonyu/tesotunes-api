<?php

namespace App\Observers\Loyalty;

use App\Models\Loyalty\LoyaltyReward;

class LoyaltyRewardObserver
{
    public function creating(LoyaltyReward $reward): void
    {
        // Default current_redemptions to 0
        if ($reward->current_redemptions === null) {
            $reward->current_redemptions = 0;
        }
    }
}
