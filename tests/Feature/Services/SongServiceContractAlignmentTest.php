<?php

namespace Tests\Feature\Services;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\PlayHistory;
use App\Models\Song;
use App\Models\User;
use App\Services\SongService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SongServiceContractAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_song_service_methods_only_return_published_songs_from_active_artists(): void
    {
        $genre = Genre::factory()->create([
            'name' => 'Alignment Genre',
            'slug' => 'alignment-genre',
        ]);

        $activeArtist = Artist::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        $inactiveArtist = Artist::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => 'suspended',
        ]);

        $matchingSong = Song::factory()->create([
            'user_id' => $activeArtist->user_id,
            'artist_id' => $activeArtist->id,
            'primary_genre_id' => $genre->id,
            'status' => 'published',
            'title' => 'Alignment Search Song',
            'created_at' => now()->subDays(2),
        ]);

        PlayHistory::factory()->create([
            'user_id' => User::factory()->create()->id,
            'song_id' => $matchingSong->id,
            'artist_id' => $activeArtist->id,
            'played_at' => now()->subDay(),
        ]);

        Song::factory()->create([
            'user_id' => $inactiveArtist->user_id,
            'artist_id' => $inactiveArtist->id,
            'primary_genre_id' => $genre->id,
            'status' => 'published',
            'title' => 'Alignment Search Song Hidden',
            'created_at' => now()->subDay(),
        ]);

        Song::factory()->create([
            'user_id' => $activeArtist->user_id,
            'artist_id' => $activeArtist->id,
            'primary_genre_id' => $genre->id,
            'status' => 'draft',
            'title' => 'Alignment Search Song Draft',
            'created_at' => now()->subDay(),
        ]);

        /** @var SongService $service */
        $service = app(SongService::class);

        $trending = $service->getTrendingSongs();
        $newReleases = $service->getNewReleases();
        $genreSongs = $service->getSongsByGenre('alignment-genre');
        $searchResults = $service->searchSongs('Alignment Search Song');

        $this->assertCount(1, $trending);
        $this->assertSame($matchingSong->id, $trending->first()->id);

        $this->assertCount(1, $newReleases);
        $this->assertSame($matchingSong->id, $newReleases->first()->id);

        $this->assertCount(1, $genreSongs->items());
        $this->assertSame($matchingSong->id, $genreSongs->items()[0]->id);

        $this->assertCount(1, $searchResults->items());
        $this->assertSame($matchingSong->id, $searchResults->items()[0]->id);
    }
}
