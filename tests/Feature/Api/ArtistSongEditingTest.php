<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Song;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\ImageUpload\CreatesUsersWithRoles;
use Tests\TestCase;

class ArtistSongEditingTest extends TestCase
{
    use CreatesUsersWithRoles;

    private User $artistUser;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');

        $this->artistUser = $this->createUserWithRole('artist');
        $this->artist = Artist::factory()->create([
            'user_id' => $this->artistUser->id,
            'can_upload' => true,
        ]);
    }

    public function test_artist_can_update_song_metadata_and_cover(): void
    {
        $originalGenre = Genre::factory()->create();
        $updatedGenre = Genre::factory()->create();
        $song = Song::factory()->create([
            'artist_id' => $this->artist->id,
            'user_id' => $this->artistUser->id,
            'primary_genre_id' => $originalGenre->id,
            'title' => 'Old Title',
            'description' => 'Old description',
            'lyrics' => 'Old lyrics',
            'price' => 0,
            'is_free' => true,
            'is_downloadable' => true,
            'is_explicit' => false,
        ]);

        $cover = UploadedFile::fake()->image('new-cover.jpg', 1200, 1200)->size(1024);

        $response = $this->actingAs($this->artistUser)->post("/api/artist/songs/{$song->id}", [
            '_method' => 'PUT',
            'title' => 'Updated Title',
            'description' => 'Updated description',
            'lyrics' => 'Updated lyrics',
            'release_date' => '2026-03-12',
            'price' => '2500',
            'is_explicit' => '1',
            'is_free' => '0',
            'is_downloadable' => '0',
            'album_id' => null,
            'genre_id' => (string) $updatedGenre->id,
            'featured_artists' => 'Guest Artist',
            'composer' => 'Composer Name',
            'producer' => 'Producer Name',
            'cover' => $cover,
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('message', 'Song updated successfully.')
            ->assertJsonPath('data.title', 'Updated Title');

        $song->refresh();

        $this->assertSame('Updated Title', $song->title);
        $this->assertSame('Updated description', $song->description);
        $this->assertSame('Updated lyrics', $song->lyrics);
        $this->assertSame('2026-03-12', optional($song->release_date)->toDateString() ?? $song->release_date);
        $this->assertEquals('2500.00', number_format((float) $song->price, 2, '.', ''));
        $this->assertTrue($song->is_explicit);
        $this->assertFalse($song->is_free);
        $this->assertFalse($song->is_downloadable);
        $this->assertSame($updatedGenre->id, $song->primary_genre_id);
        $this->assertSame('Guest Artist', $song->featured_artists);
        $this->assertSame('Composer Name', $song->composer);
        $this->assertSame('Producer Name', $song->producer);
        $this->assertNotNull($song->artwork);
        Storage::disk('public')->assertExists($song->artwork);
        $this->assertDatabaseHas('song_genres', [
            'song_id' => $song->id,
            'genre_id' => $updatedGenre->id,
        ]);
    }

    public function test_artist_song_detail_returns_editor_fields(): void
    {
        $genre = Genre::factory()->create();
        $song = Song::factory()->create([
            'artist_id' => $this->artist->id,
            'user_id' => $this->artistUser->id,
            'primary_genre_id' => $genre->id,
            'title' => 'Editable Song',
            'description' => 'Editable description',
            'lyrics' => 'Editable lyrics',
            'featured_artists' => 'Guest Artist',
            'composer' => 'Composer Name',
            'producer' => 'Producer Name',
            'is_downloadable' => false,
        ]);

        $response = $this->actingAs($this->artistUser)
            ->getJson("/api/artist/songs/{$song->id}");

        $response->assertOk()
            ->assertJsonPath('data.title', 'Editable Song')
            ->assertJsonPath('data.genre_id', $genre->id)
            ->assertJsonPath('data.primary_genre_id', $genre->id)
            ->assertJsonPath('data.featured_artists', 'Guest Artist')
            ->assertJsonPath('data.composer', 'Composer Name')
            ->assertJsonPath('data.producer', 'Producer Name')
            ->assertJsonPath('data.is_downloadable', false);
    }
}
