<?php

namespace Database\Seeders;

use App\Models\ReferralMilestone;
use Illuminate\Database\Seeder;

/**
 * The referral ladder.
 *
 * These are starting figures, not settled policy — they are rows precisely so
 * they can be tuned from the admin surface once there is real data on what a
 * referred account is worth. The shape is what matters: a first rung that is
 * genuinely easy so the programme starts paying early, then widening gaps so
 * the later rungs stay meaningful.
 *
 * Idempotent, keyed on `key`, so re-running never duplicates a rung or resets
 * a figure an operator has since changed.
 */
class ReferralMilestoneSeeder extends Seeder
{
    public function run(): void
    {
        $milestones = [
            [
                'key' => 'first_invite',
                'name' => 'First Invite',
                'description' => 'Bring your first person to TesoTunes.',
                'referrals_required' => 1,
                'reward_value' => 250,
                'badge_name' => 'Starter',
                'badge_icon' => '🌱',
                'badge_tier' => ReferralMilestone::TIER_BRONZE,
                'sort_order' => 1,
            ],
            [
                'key' => 'circle_of_five',
                'name' => 'Circle of Five',
                'description' => 'Five people joined through your link.',
                'referrals_required' => 5,
                'reward_value' => 1_500,
                'badge_name' => 'Connector',
                'badge_icon' => '🔗',
                'badge_tier' => ReferralMilestone::TIER_SILVER,
                'sort_order' => 2,
            ],
            [
                'key' => 'ten_strong',
                'name' => 'Ten Strong',
                'description' => 'Ten people joined through your link.',
                'referrals_required' => 10,
                'reward_value' => 4_000,
                'badge_name' => 'Champion',
                'badge_icon' => '🏆',
                'badge_tier' => ReferralMilestone::TIER_GOLD,
                'sort_order' => 3,
            ],
            [
                'key' => 'community_builder',
                'name' => 'Community Builder',
                'description' => 'Twenty-five people joined through your link.',
                'referrals_required' => 25,
                'reward_value' => 12_000,
                'badge_name' => 'Builder',
                'badge_icon' => '🏗️',
                'badge_tier' => ReferralMilestone::TIER_PLATINUM,
                'sort_order' => 4,
            ],
            [
                'key' => 'platform_ambassador',
                'name' => 'Platform Ambassador',
                'description' => 'Fifty people joined through your link.',
                'referrals_required' => 50,
                'reward_value' => 30_000,
                'badge_name' => 'Ambassador',
                'badge_icon' => '👑',
                'badge_tier' => ReferralMilestone::TIER_DIAMOND,
                'sort_order' => 5,
            ],
        ];

        foreach ($milestones as $milestone) {
            ReferralMilestone::firstOrCreate(
                ['key' => $milestone['key']],
                array_merge($milestone, ['reward_type' => ReferralMilestone::REWARD_CREDITS, 'is_active' => true]),
            );
        }
    }
}
