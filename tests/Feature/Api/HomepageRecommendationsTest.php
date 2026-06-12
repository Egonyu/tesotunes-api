<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\PlayHistory;
use App\Models\Song;
use App\Models\User;
use App\Services\HomepageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The homepage recommendation layers (docs/architecture/RECOMMENDATIONS.md):
 * windowed trending, candidate-pool scoring with daily rotation, and the
 * co-listen collaborative layer.
 */
class HomepageRecommendationsTest extends TestCase
{
    use DatabaseTransactions;

    private function publishedSong(array $attributes = []): Song
    {
        $artist = Artist::factory()->create(['status' => 'approved']);

        return Song::factory()->create(array_merge([
            'artist_id' => $artist->id,
            'status' => 'published',
            'visibility' => 'public',
        ], $attributes));
    }

    private function modulesByType(array $payload): array
    {
        return collect($payload['modules'] ?? [])->keyBy('type')->all();
    }

    public function test_trending_prefers_recent_engagement_over_stale_play_counts(): void
    {
        $staleHit = $this->publishedSong(['play_count' => 100000, 'title' => 'Old Anthem']);
        $risingSong = $this->publishedSong(['play_count' => 10, 'title' => 'New Heat']);

        $listeners = User::factory()->count(3)->create();
        foreach ($listeners as $listener) {
            PlayHistory::factory()->create([
                'user_id' => $listener->id,
                'song_id' => $risingSong->id,
                'artist_id' => $risingSong->artist_id,
                'played_at' => now()->subDay(),
                'completed' => true,
                'skipped' => false,
            ]);
        }

        $payload = app(HomepageService::class)->build(null, 'all');
        $modules = $this->modulesByType($payload);

        $this->assertArrayHasKey('quick_picks', $modules);
        $quickPickIds = collect($modules['quick_picks']['items'])->pluck('id');

        $this->assertTrue($quickPickIds->contains($risingSong->id), 'recently played song must rank in trending');
        $risingPosition = $quickPickIds->search($risingSong->id);
        $stalePosition = $quickPickIds->search($staleHit->id);

        if ($stalePosition !== false) {
            $this->assertLessThan($stalePosition, $risingPosition, 'windowed engagement outranks stale all-time plays');
        }
    }

    public function test_recommended_today_can_surface_new_songs_despite_older_catalog(): void
    {
        // Regression: the old unordered limit(40) candidate pool meant only
        // the oldest rows were ever scored, so new music never surfaced.
        Song::factory()->count(45)->create([
            'artist_id' => Artist::factory()->create(['status' => 'approved'])->id,
            'status' => 'published',
            'visibility' => 'public',
            'created_at' => now()->subYears(2),
            'play_count' => 5,
            'like_count' => 0,
            'is_featured' => false,
        ]);

        $freshBanger = $this->publishedSong([
            'created_at' => now()->subDay(),
            'play_count' => 90000,
            'like_count' => 20000,
            'is_featured' => true,
            'title' => 'Fresh Banger',
        ]);

        $payload = app(HomepageService::class)->build(null, 'all');
        $modules = $this->modulesByType($payload);

        $this->assertArrayHasKey('recommended_today', $modules);
        $ids = collect($modules['recommended_today']['items'])->pluck('id');

        $this->assertTrue($ids->contains($freshBanger->id), 'new high-engagement song must enter the candidate pool');
    }

    public function test_because_you_listened_includes_co_listened_songs_from_other_artists(): void
    {
        $me = User::factory()->create();

        $favouriteArtist = Artist::factory()->create(['status' => 'approved']);
        $favouriteSong = Song::factory()->create([
            'artist_id' => $favouriteArtist->id,
            'status' => 'published',
            'visibility' => 'public',
        ]);

        $coListenedSong = $this->publishedSong(['title' => 'Co-listen Gem']);

        // My history: I play the favourite artist.
        PlayHistory::factory()->create([
            'user_id' => $me->id,
            'song_id' => $favouriteSong->id,
            'artist_id' => $favouriteArtist->id,
            'played_at' => now()->subHours(2),
            'completed' => true,
            'skipped' => false,
        ]);

        // Other fans of the same artist also play the co-listened song.
        foreach (User::factory()->count(3)->create() as $fan) {
            PlayHistory::factory()->create([
                'user_id' => $fan->id,
                'song_id' => $favouriteSong->id,
                'artist_id' => $favouriteArtist->id,
                'played_at' => now()->subDays(3),
                'completed' => true,
                'skipped' => false,
            ]);
            PlayHistory::factory()->create([
                'user_id' => $fan->id,
                'song_id' => $coListenedSong->id,
                'artist_id' => $coListenedSong->artist_id,
                'played_at' => now()->subDays(2),
                'completed' => true,
                'skipped' => false,
            ]);
        }

        $payload = app(HomepageService::class)->build($me, 'all');
        $modules = $this->modulesByType($payload);

        $this->assertArrayHasKey('because_you_listened', $modules);
        $items = collect($modules['because_you_listened']['items']);

        $this->assertTrue(
            $items->pluck('id')->contains($coListenedSong->id),
            'co-listened song by a different artist must appear'
        );
        $this->assertSame(
            'Listeners also play',
            $items->firstWhere('id', $coListenedSong->id)['eyebrow'],
            'co-listen items carry their own label'
        );
    }

    public function test_guest_homepage_builds_without_personal_signals(): void
    {
        $this->publishedSong();

        $response = $this->getJson('/api/homepage');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotEmpty($response->json('data.modules'));
    }
}
