<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Artist;
use App\Models\PlayHistory;
use App\Models\Song;
use App\Models\User;

class TrendingSongsApiTest extends ResponseStandardizationTestCase
{
    public function test_trending_songs_returns_standardized_song_resource_collection(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $songs = Song::factory()->count(3)->create([
            'user_id' => $user->id,
            'artist_id' => $artist->id,
            'status' => 'published',
            'duration_seconds' => 185,
            'audio_file_128' => 'songs/128/test-stream.mp3',
        ]);

        foreach ($songs as $song) {
            PlayHistory::factory()->create([
                'user_id' => $user->id,
                'song_id' => $song->id,
                'artist_id' => $artist->id,
                'played_at' => now()->subDay(),
            ]);
        }

        $response = $this->getJson('/api/trending');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'title',
                    'slug',
                    'duration_seconds',
                    'duration_formatted',
                    'audio_url',
                    'stream_url',
                    'preview_url',
                    'artwork_url',
                    'artist',
                    'links',
                ]],
            ])
            ->assertJsonMissing(['success' => true]);
    }
}
