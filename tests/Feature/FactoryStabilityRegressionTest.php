<?php

namespace Tests\Feature;

use App\Models\ArtistProfile;
use App\Models\Genre;
use App\Models\Song;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactoryStabilityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_adjacent_factories_create_rows_against_baseline_schema(): void
    {
        $genre = Genre::factory()->create();
        $plan = SubscriptionPlan::factory()->create();
        $profile = ArtistProfile::factory()->create();
        $song = Song::factory()->create();

        $this->assertDatabaseHas('genres', [
            'id' => $genre->id,
            'uuid' => $genre->uuid,
        ]);

        $this->assertDatabaseHas('subscription_plans', [
            'id' => $plan->id,
            'uuid' => $plan->uuid,
        ]);

        $this->assertDatabaseHas('artist_profiles', [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
        ]);

        $this->assertDatabaseHas('songs', [
            'id' => $song->id,
            'uuid' => $song->uuid,
        ]);
    }
}
