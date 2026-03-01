<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\Loyalty\LoyaltyReward;
use App\Models\User;
use Illuminate\Database\Seeder;

class LoyaltySeeder extends Seeder
{
    public function run(): void
    {
        $artists = Artist::take(3)->get();

        if ($artists->isEmpty()) {
            $this->command->warn('No artists found — skipping loyalty seeder.');

            return;
        }

        foreach ($artists as $artist) {
            $card = LoyaltyCard::factory()->create([
                'artist_id' => $artist->id,
                'name' => $artist->name.' Fan Club',
            ]);

            // Create rewards
            LoyaltyReward::factory()->content()->create(['loyalty_card_id' => $card->id, 'required_tier' => 'bronze']);
            LoyaltyReward::factory()->discount()->create(['loyalty_card_id' => $card->id, 'required_tier' => 'silver']);
            LoyaltyReward::factory()->forGold()->create(['loyalty_card_id' => $card->id]);

            // Add some members
            $users = User::inRandomOrder()->take(5)->get();

            foreach ($users as $index => $user) {
                $tier = match (true) {
                    $index < 2 => 'bronze',
                    $index < 4 => 'silver',
                    default => 'gold',
                };

                LoyaltyCardMember::factory()->create([
                    'loyalty_card_id' => $card->id,
                    'user_id' => $user->id,
                    'tier' => $tier,
                ]);
            }

            $card->update(['total_members' => $users->count()]);
        }

        $this->command->info('Loyalty cards, rewards, and members seeded.');
    }
}
