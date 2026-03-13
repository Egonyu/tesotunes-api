<?php

namespace Tests\Feature\Api;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Song;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\ImageUpload\CreatesUsersWithRoles;
use Tests\TestCase;

class ArtistAlbumEditingTest extends TestCase
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
        ]);
    }

    public function test_artist_can_create_album_with_type_and_genre(): void
    {
        $genre = Genre::factory()->create(['name' => 'Afrobeats', 'slug' => 'afrobeats']);
        $cover = UploadedFile::fake()->image('album-cover.jpg', 1200, 1200)->size(1024);

        $response = $this->actingAs($this->artistUser)
            ->post('/api/artist/albums', [
                'title' => 'Debut Album',
                'description' => 'Album description',
                'release_date' => '2026-03-20',
                'type' => 'ep',
                'genre' => $genre->slug,
                'cover_image' => $cover,
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('message', 'Album created successfully')
            ->assertJsonPath('data.title', 'Debut Album');

        $album = Album::latest('id')->firstOrFail();

        $this->assertSame($this->artist->id, $album->artist_id);
        $this->assertSame('ep', $album->album_type);
        $this->assertSame($genre->id, $album->primary_genre_id);
        $this->assertNotNull($album->artwork);
        Storage::disk('public')->assertExists($album->artwork);
    }

    public function test_artist_can_view_album_detail_for_edit_flow(): void
    {
        $genre = Genre::factory()->create();
        $album = Album::factory()->create([
            'artist_id' => $this->artist->id,
            'primary_genre_id' => $genre->id,
            'album_type' => 'single',
            'title' => 'Editable Album',
            'status' => 'published',
        ]);

        Song::factory()->create([
            'artist_id' => $this->artist->id,
            'album_id' => $album->id,
            'user_id' => $this->artistUser->id,
            'title' => 'Track One',
            'duration_seconds' => 180,
            'play_count' => 42,
        ]);

        $response = $this->actingAs($this->artistUser)
            ->getJson("/api/artist/albums/{$album->id}");

        $response->assertOk()
            ->assertJsonPath('data.title', 'Editable Album')
            ->assertJsonPath('data.type', 'single')
            ->assertJsonPath('data.genre_id', $genre->id)
            ->assertJsonPath('data.songs.0.title', 'Track One')
            ->assertJsonPath('data.songs.0.duration_seconds', 180);
    }

    public function test_artist_can_update_album_metadata_and_cover(): void
    {
        $originalGenre = Genre::factory()->create();
        $updatedGenre = Genre::factory()->create(['name' => 'Dancehall', 'slug' => 'dancehall']);
        $album = Album::factory()->create([
            'artist_id' => $this->artist->id,
            'primary_genre_id' => $originalGenre->id,
            'album_type' => 'album',
            'title' => 'Old Album',
        ]);

        $cover = UploadedFile::fake()->image('updated-cover.jpg', 1200, 1200)->size(1024);

        $response = $this->actingAs($this->artistUser)
            ->post("/api/artist/albums/{$album->id}", [
                '_method' => 'PUT',
                'title' => 'Updated Album',
                'description' => 'Updated description',
                'release_date' => '2026-03-25',
                'type' => 'ep',
                'genre' => $updatedGenre->slug,
                'cover_image' => $cover,
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('message', 'Album updated successfully.')
            ->assertJsonPath('data.title', 'Updated Album');

        $album->refresh();

        $this->assertSame('Updated Album', $album->title);
        $this->assertSame('Updated description', $album->description);
        $this->assertSame('ep', $album->album_type);
        $this->assertSame($updatedGenre->id, $album->primary_genre_id);
        $this->assertSame('2026-03-25', optional($album->release_date)->toDateString() ?? $album->release_date);
        $this->assertNotNull($album->artwork);
        Storage::disk('public')->assertExists($album->artwork);
    }
}
