<?php

namespace Database\Seeders;

use App\Models\CreditMilestone;
use Illuminate\Database\Seeder;

/**
 * The credit goal ladder.
 *
 * Kept from the old hardcoded list: the thresholds and the credit bonuses.
 * Dropped, because nothing on the platform delivers them: custom themes,
 * priority support and "VIP features". Removed outright: "Artist verification"
 * at 2,500 — verification says an account really is that artist, and must only
 * ever come from moderator review, never from credits anyone can farm.
 *
 * Badges are shown on the credits page once a goal is claimed.
 *
 * Idempotent, keyed on `key`, so re-running never duplicates a rung or resets
 * a figure an operator has since changed.
 */
class CreditMilestoneSeeder extends Seeder
{
    public function run(): void
    {
        $milestones = [
            [
                'key' => 'first_hundred',
                'name' => 'First 100',
                'description' => 'Earn your first 100 credits by listening, sharing and joining in.',
                'credits_required' => 100,
                'reward_value' => 10,
                'badge_name' => 'Rising Fan',
                'badge_icon' => '🌱',
                'badge_tier' => CreditMilestone::TIER_BRONZE,
                'sort_order' => 1,
            ],
            [
                'key' => 'five_hundred',
                'name' => 'Regular',
                'description' => 'Earn 500 credits through activity.',
                'credits_required' => 500,
                'reward_value' => 25,
                'badge_name' => 'Regular',
                'badge_icon' => '🎧',
                'badge_tier' => CreditMilestone::TIER_SILVER,
                'sort_order' => 2,
            ],
            [
                'key' => 'one_thousand',
                'name' => 'Devoted',
                'description' => 'Earn 1,000 credits through activity.',
                'credits_required' => 1_000,
                'reward_value' => 50,
                'badge_name' => 'Devoted',
                'badge_icon' => '🔥',
                'badge_tier' => CreditMilestone::TIER_GOLD,
                'sort_order' => 3,
            ],
            [
                'key' => 'five_thousand',
                'name' => 'Superfan',
                'description' => 'Earn 5,000 credits through activity.',
                'credits_required' => 5_000,
                'reward_value' => 200,
                'badge_name' => 'Superfan',
                'badge_icon' => '⭐',
                'badge_tier' => CreditMilestone::TIER_PLATINUM,
                'sort_order' => 4,
            ],
            [
                'key' => 'tesotunes_ambassador',
                'name' => 'TesoTunes Ambassador',
                'description' => 'Earn 10,000 credits through activity.',
                'credits_required' => 10_000,
                'reward_value' => 500,
                'badge_name' => 'TesoTunes Ambassador',
                'badge_icon' => '👑',
                'badge_tier' => CreditMilestone::TIER_DIAMOND,
                'sort_order' => 5,
            ],
        ];

        foreach ($milestones as $milestone) {
            CreditMilestone::firstOrCreate(
                ['key' => $milestone['key']],
                array_merge($milestone, ['reward_type' => CreditMilestone::REWARD_CREDITS, 'is_active' => true]),
            );
        }
    }
}
