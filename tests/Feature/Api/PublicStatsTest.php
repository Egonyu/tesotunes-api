<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Marketing pages claimed "10K+ songs" and "50K+ users" as fixed copy. They
 * now read these counts, so the counts must be the real ones.
 */
class PublicStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_count_published_songs_and_artists_with_something_to_play(): void
    {
        Cache::flush();

        $playable = Artist::factory()->create();
        Song::factory()->count(2)->create(['artist_id' => $playable->id, 'status' => 'published']);

        $unreleased = Artist::factory()->create();
        Song::factory()->create(['artist_id' => $unreleased->id, 'status' => 'draft']);

        $members = User::query()->count();

        $this->getJson('/api/public/stats')
            ->assertOk()
            ->assertJsonPath('data.songs', 2)
            ->assertJsonPath('data.artists', 1)
            ->assertJsonPath('data.members', $members);
    }

    public function test_stats_are_public(): void
    {
        $this->getJson('/api/public/stats')->assertOk()->assertJsonStructure(['data' => ['songs', 'artists', 'members']]);
    }
}
