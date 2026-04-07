<?php

namespace Tests\Feature\Api\ResponseStandardization;

use App\Models\Artist;
use App\Models\Song;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class MobileApiTest extends ResponseStandardizationTestCase
{
    public function test_mobile_trending_songs_returns_canonical_duration_fields(): void
    {
        $artist = Artist::factory()->create();
        $song = Song::factory()->create([
            'artist_id' => $artist->id,
            'status' => 'published',
            'visibility' => 'public',
            'duration_seconds' => 185,
        ]);

        $response = $this->getJson('/api/mobile/trending/songs');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $song->id)
            ->assertJsonPath('data.0.duration_seconds', 185)
            ->assertJsonPath('data.0.duration_formatted', '3:05');

        $this->assertArrayNotHasKey('duration', $response->json('data.0'));
    }

    public function test_mobile_song_download_returns_canonical_duration_field_and_persists_polymorphic_download(): void
    {
        Storage::fake('digitalocean');

        $user = User::factory()->create();
        $artist = Artist::factory()->create();
        $song = Song::factory()->create([
            'artist_id' => $artist->id,
            'status' => 'published',
            'visibility' => 'public',
            'is_downloadable' => true,
            'duration_seconds' => 211,
            'audio_file_128' => 'songs/128/mobile-download.mp3',
        ]);

        Storage::disk('digitalocean')->put('songs/128/mobile-download.mp3', 'fake-audio');

        $response = $this->actingAs($user)->getJson("/api/mobile/downloads/song/{$song->id}");

        $response->assertOk()
            ->assertJsonPath('song.id', $song->id)
            ->assertJsonPath('song.duration_seconds', 211)
            ->assertJsonPath('quality', '128kbps');

        $this->assertArrayNotHasKey('duration', $response->json('song'));

        $this->assertDatabaseHas('downloads', [
            'user_id' => $user->id,
            'downloadable_type' => Song::class,
            'downloadable_id' => $song->id,
        ]);
    }
}
