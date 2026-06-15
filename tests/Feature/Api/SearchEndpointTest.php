<?php

namespace Tests\Feature\Api;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Playlist;
use App\Models\Song;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SearchEndpointTest extends TestCase
{
    use DatabaseTransactions;

    public function test_multi_character_search_returns_results_across_types_without_error(): void
    {
        $artist = Artist::factory()->create(['stage_name' => 'Lovejoy Band', 'status' => 'approved']);
        Song::factory()->create(['title' => 'Love Song', 'artist_id' => $artist->id, 'status' => 'published']);
        Album::factory()->create(['title' => 'Love Album', 'artist_id' => $artist->id, 'status' => 'published']);

        $response = $this->getJson('/api/v1/public/search?q=love');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['query', 'type', 'results' => ['songs', 'artists', 'albums', 'playlists'], 'total_results']]);

        $this->assertGreaterThanOrEqual(1, count($response->json('data.results.songs')));
        $this->assertGreaterThanOrEqual(1, count($response->json('data.results.artists')));
        $this->assertGreaterThanOrEqual(1, count($response->json('data.results.albums')));
    }

    public function test_playlist_search_uses_the_name_column_and_does_not_crash(): void
    {
        // Regression: the playlist branch queried a non-existent `title` column,
        // throwing a QueryException on every 2+ character search.
        Playlist::factory()->create(['name' => 'Lovely Vibes', 'visibility' => 'public', 'is_public' => true]);

        $response = $this->getJson('/api/v1/public/search?q=lovely&type=playlists');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data.results.playlists')));
    }

    public function test_single_character_query_returns_empty_without_error(): void
    {
        $this->getJson('/api/v1/public/search?q=a')
            ->assertOk()
            ->assertJsonPath('data.total_results', 0);
    }
}
