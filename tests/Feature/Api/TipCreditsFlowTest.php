<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TipCreditsFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->firstOrCreate(
            ['name' => 'artist'],
            ['display_name' => 'Artist', 'description' => 'Artist account', 'is_active' => true, 'priority' => 2]
        );
    }

    public function test_authenticated_user_can_tip_artist_with_credits(): void
    {
        $sender = User::factory()->create();
        $sender->ensureCreditWallet()->update(['balance' => 500]);

        $artistUser = User::factory()->create([
            'is_artist' => true,
        ]);
        $artistUser->assignRole('artist', $artistUser->id);
        $artist = Artist::factory()->create([
            'user_id' => $artistUser->id,
        ]);

        $response = $this->actingAs($sender)->postJson('/api/tips', [
            'recipient_id' => $artist->id,
            'recipient_type' => 'artist',
            'amount' => 125,
            'message' => 'Keep going',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', 125)
            ->assertJsonPath('data.credits_remaining', 375);

        $this->assertSame(375, $sender->fresh()->credits);
        $this->assertDatabaseHas('payments', [
            'user_id' => $sender->id,
            'payable_type' => Artist::class,
            'payable_id' => $artist->id,
            'payment_type' => 'tip',
            'payment_method' => 'credits',
            'currency' => 'credits',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $sender->id,
            'source' => 'artist_tip',
            'amount' => 125,
        ]);
    }
}
