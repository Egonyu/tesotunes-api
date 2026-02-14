<?php

namespace Database\Seeders;

use App\Models\CreditRate;
use Illuminate\Database\Seeder;

class CreditRateSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            [
                'activity_type' => 'song_play',
                'display_name' => 'Song Play',
                'description' => 'Credits earned per song play',
                'base_rate' => 0.50,
                'max_daily' => 50.00,
                'cooldown_minutes' => 1,
                'cost_credits' => 0,
                'sort_order' => 1,
            ],
            [
                'activity_type' => 'song_download',
                'display_name' => 'Song Download',
                'description' => 'Credits required to download a song',
                'base_rate' => 0.00,
                'max_daily' => 0.00,
                'cooldown_minutes' => 0,
                'cost_credits' => 100,
                'sort_order' => 2,
            ],
            [
                'activity_type' => 'daily_login',
                'display_name' => 'Daily Login Bonus',
                'description' => 'Credits earned for daily login',
                'base_rate' => 5.00,
                'max_daily' => 5.00,
                'cooldown_minutes' => 1440,
                'cost_credits' => 0,
                'sort_order' => 3,
            ],
            [
                'activity_type' => 'referral',
                'display_name' => 'Referral Bonus',
                'description' => 'Credits earned when referred user signs up',
                'base_rate' => 50.00,
                'max_daily' => 500.00,
                'cooldown_minutes' => 0,
                'cost_credits' => 0,
                'sort_order' => 4,
            ],
            [
                'activity_type' => 'ad_watch',
                'display_name' => 'Watch Advertisement',
                'description' => 'Credits earned for watching an ad',
                'base_rate' => 2.00,
                'max_daily' => 20.00,
                'cooldown_minutes' => 5,
                'cost_credits' => 0,
                'sort_order' => 5,
            ],
            [
                'activity_type' => 'premium_subscription',
                'display_name' => 'Premium Subscription',
                'description' => 'Monthly premium subscription cost',
                'base_rate' => 0.00,
                'max_daily' => 0.00,
                'cooldown_minutes' => 0,
                'cost_credits' => 500,
                'sort_order' => 6,
            ],
        ];

        foreach ($rates as $rate) {
            CreditRate::updateOrCreate(
                ['activity_type' => $rate['activity_type']],
                array_merge($rate, ['is_active' => true])
            );
        }
    }
}
