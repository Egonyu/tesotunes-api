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

    public function test_artist_can_upload_song_when_soft_deleted_slug_already_exists(): void
    {
        $existing = Song::factory()->create([
            'artist_id' => $this->artist->id,
            'user_id' => $this->artistUser->id,
            'title' => 'Teete',
            'slug' => 'teete',
            'status' => 'draft',
        ]);
        $existing->delete();

        $audio = UploadedFile::fake()->create('teete.mp3', 1024, 'audio/mpeg');

        $response = $this->actingAs($this->artistUser)->post('/api/artist/songs', [
            'title' => 'Teete',
            'audio' => $audio,
            'is_free' => '1',
            'is_downloadable' => '1',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Teete');

        $this->assertDatabaseHas('songs', [
            'title' => 'Teete',
            'slug' => 'teete-1',
            'artist_id' => $this->artist->id,
            'deleted_at' => null,
        ]);
    }

    public function test_artist_can_request_a_direct_song_upload_target(): void
    {
        config([
            'filesystems.default' => 'digitalocean',
            'filesystems.media_disk' => 'digitalocean',
        ]);

        $response = $this->actingAs($this->artistUser)->postJson('/api/artist/songs/upload-target', [
            'kind' => 'audio',
            'filename' => 'mixtape.mp3',
            'content_type' => 'audio/mpeg',
            'size_bytes' => 200 * 1024 * 1024,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.kind', 'audio')
            ->assertJsonPath('data.method', 'POST')
            ->assertJsonPath('data.disk', 'digitalocean')
            ->assertJsonPath('data.max_file_size_bytes', config('music.storage.limits.max_audio_size'));

        $this->assertStringContainsString(
            'songs/audio/direct/'.$this->artistUser->id.'/',
            (string) $response->json('data.key')
        );
        $this->assertSame(
            $response->json('data.key'),
            $response->json('data.fields.key')
        );
    }

    public function test_artist_can_create_song_from_direct_cloud_upload_references(): void
    {
        config([
            'filesystems.default' => 'digitalocean',
            'filesystems.media_disk' => 'digitalocean',
        ]);
        Storage::fake('digitalocean');

        $audioKey = 'songs/audio/direct/'.$this->artistUser->id.'/'.uniqid('audio_', true).'.mp3';
        $coverKey = 'songs/artwork/direct/'.$this->artistUser->id.'/'.uniqid('cover_', true).'.jpg';
        Storage::disk('digitalocean')->put($audioKey, str_repeat('a', 1024));
        Storage::disk('digitalocean')->put($coverKey, str_repeat('b', 512));

        $title = 'Direct Upload Song '.uniqid();

        $response = $this->actingAs($this->artistUser)->postJson('/api/artist/songs', [
            'title' => $title,
            'uploaded_audio_key' => $audioKey,
            'uploaded_audio_original_name' => 'direct-upload-song.mp3',
            'uploaded_audio_size_bytes' => 1024,
            'uploaded_cover_key' => $coverKey,
            'uploaded_cover_original_name' => 'direct-upload-song.jpg',
            'is_free' => true,
            'is_downloadable' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', $title);

        $song = Song::query()->where('title', $title)->latest('id')->firstOrFail();

        $this->assertSame($audioKey, $song->audio_file_original);
        $this->assertSame($audioKey, $song->audio_file_320);
        $this->assertSame($coverKey, $song->artwork);
        $this->assertSame(1024, $song->file_size_bytes);
    }

    public function test_artist_can_upload_profile_avatar_via_dedicated_route(): void
    {
        $avatar = UploadedFile::fake()->image('artist-avatar.jpg', 400, 400);

        $response = $this->actingAs($this->artistUser)->post('/api/artist/profile/avatar', [
            'avatar' => $avatar,
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Avatar uploaded successfully.');

        $this->artist->refresh();

        $this->assertNotNull($this->artist->avatar);
        Storage::disk('public')->assertExists($this->artist->avatar);
        $this->assertStringContainsString($this->artist->avatar, (string) $response->json('data.url'));
    }
}
