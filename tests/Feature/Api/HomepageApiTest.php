<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\Event;
use App\Models\PlayHistory;
use App\Models\Playlist;
use App\Models\Song;
use App\Models\User;
use App\Models\UserFollow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_curated_homepage_returns_personalized_modules_for_high_signal_user(): void
    {
        $listener = User::factory()->create();
        $artistOwner = User::factory()->create();
        $followedArtistOwner = User::factory()->create();

        $primaryArtist = Artist::factory()->verified()->create([
            'user_id' => $artistOwner->id,
            'status' => 'active',
            'stage_name' => 'Joshua Baraka',
            'slug' => 'joshua-baraka',
        ]);
        $followedArtist = Artist::factory()->verified()->create([
            'user_id' => $followedArtistOwner->id,
            'status' => 'active',
            'stage_name' => 'Amina Kon',
            'slug' => 'amina-kon',
        ]);

        $recentSong = Song::factory()->create([
            'artist_id' => $primaryArtist->id,
            'user_id' => $artistOwner->id,
            'title' => 'Teso Nights',
            'slug' => 'teso-nights',
            'status' => 'published',
            'visibility' => 'public',
            'primary_genre_id' => null,
            'play_count' => 4200,
            'is_featured' => true,
        ]);
        $followedSong = Song::factory()->create([
            'artist_id' => $followedArtist->id,
            'user_id' => $followedArtistOwner->id,
            'title' => 'Ug Flow',
            'slug' => 'ug-flow',
            'status' => 'published',
            'visibility' => 'public',
            'play_count' => 2200,
        ]);
        Song::factory()->count(8)->create([
            'artist_id' => $primaryArtist->id,
            'user_id' => $artistOwner->id,
            'status' => 'published',
            'visibility' => 'public',
        ]);

        Playlist::factory()->create([
            'name' => 'Kampala Radio',
            'slug' => 'kampala-radio',
            'visibility' => 'public',
            'is_featured' => true,
            'user_id' => $artistOwner->id,
        ]);

        Event::factory()->published()->upcoming()->create([
            'title' => 'Live in Kampala',
            'slug' => 'live-in-kampala',
        ]);

        PlayHistory::query()->create([
            'user_id' => $listener->id,
            'song_id' => $recentSong->id,
            'artist_id' => $primaryArtist->id,
            'played_at' => now()->subHour(),
            'duration_played_seconds' => 180,
            'completed' => true,
            'skipped' => false,
            'completion_percentage' => 100,
            'device_type' => 'mobile',
            'quality' => '128',
        ]);

        UserFollow::query()->create([
            'follower_id' => $listener->id,
            'followable_type' => Artist::class,
            'followable_id' => $followedArtist->id,
        ]);

        $response = $this->actingAs($listener)->getJson('/api/homepage');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.theme', 'classic_home')
            ->assertJsonPath('data.audience', 'personalized');

        $moduleTypes = collect($response->json('data.modules'))->pluck('type');

        $this->assertTrue($moduleTypes->contains('recently_played'));
        $this->assertTrue($moduleTypes->contains('because_you_listened'));
        $this->assertTrue($moduleTypes->contains('new_from_followed'));
        $this->assertTrue($moduleTypes->contains('popular_radio'));
    }

    public function test_curated_homepage_returns_cold_start_modules_for_guests(): void
    {
        $artistOwner = User::factory()->create();
        $artist = Artist::factory()->verified()->create([
            'user_id' => $artistOwner->id,
            'status' => 'active',
        ]);

        Song::factory()->count(6)->create([
            'artist_id' => $artist->id,
            'user_id' => $artistOwner->id,
            'status' => 'published',
            'visibility' => 'public',
            'is_featured' => true,
        ]);

        Playlist::factory()->create([
            'name' => 'East Africa Heat',
            'slug' => 'east-africa-heat',
            'visibility' => 'public',
            'is_featured' => true,
            'user_id' => $artistOwner->id,
        ]);

        Event::factory()->published()->upcoming()->create();

        $response = $this->getJson('/api/homepage');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.audience', 'cold_start');

        $moduleTypes = collect($response->json('data.modules'))->pluck('type');

        $this->assertTrue($moduleTypes->contains('hero_feature'));
        $this->assertTrue($moduleTypes->contains('recommended_today'));
        $this->assertTrue($moduleTypes->contains('popular_radio'));
        $this->assertTrue($moduleTypes->contains('ecosystem_spotlight'));
    }

    public function test_curated_homepage_radio_mode_marks_chip_active_and_focuses_radio_modules(): void
    {
        $owner = User::factory()->create();
        $artist = Artist::factory()->verified()->create([
            'user_id' => $owner->id,
            'status' => 'active',
        ]);

        Song::factory()->count(3)->create([
            'artist_id' => $artist->id,
            'user_id' => $owner->id,
            'status' => 'published',
            'visibility' => 'public',
            'is_featured' => true,
        ]);

        Playlist::factory()->count(2)->create([
            'visibility' => 'public',
            'is_featured' => true,
            'user_id' => $owner->id,
        ]);

        $response = $this->getJson('/api/homepage?mode=radio');

        $response->assertOk()
            ->assertJsonPath('data.chips.2.id', 'radio')
            ->assertJsonPath('data.chips.2.active', true)
            ->assertJsonPath('data.headline', 'Stations, mixes, and lean-back listening.');

        $moduleTypes = collect($response->json('data.modules'))->pluck('type');
        $this->assertTrue($moduleTypes->contains('popular_radio'));
        $this->assertFalse($moduleTypes->contains('ecosystem_spotlight'));
    }
}
