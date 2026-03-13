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

class AdminSongContractTest extends TestCase
{
    use CreatesUsersWithRoles;

    private User $admin;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->admin = $this->createUserWithRole('admin');
        $artistUser = $this->createUserWithRole('artist');
        $this->artist = Artist::factory()->create([
            'user_id' => $artistUser->id,
        ]);
    }

    public function test_admin_can_create_song_with_supported_form_fields(): void
    {
        $genre = Genre::factory()->create();
        $featuredArtist = Artist::factory()->create([
            'user_id' => $this->createUserWithRole('artist')->id,
        ]);

        $response = $this->actingAs($this->admin)->post('/api/admin/songs', [
            'title' => 'Admin Created Song',
            'artist_id' => $this->artist->id,
            'status' => 'published',
            'explicit' => '1',
            'is_featured' => '1',
            'description' => 'Created in admin',
            'composer' => 'Composer Name',
            'producer' => 'Producer Name',
            'price' => '2500',
            'is_free' => '0',
            'is_downloadable' => '0',
            'genre_ids' => [$genre->id],
            'featured_artists' => [$featuredArtist->id],
            'audio_file' => UploadedFile::fake()->create('song.mp3', 1024, 'audio/mpeg'),
            'cover_image' => UploadedFile::fake()->image('cover.jpg', 1200, 1200),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Song created successfully');

        $song = Song::latest('id')->firstOrFail();
        $this->assertSame($this->artist->id, $song->artist_id);
        $this->assertSame('Composer Name', $song->composer);
        $this->assertSame('Producer Name', $song->producer);
        $this->assertSame($genre->id, $song->primary_genre_id);
        $this->assertFalse($song->is_free);
        $this->assertFalse($song->is_downloadable);
        $this->assertTrue($song->is_featured);
        $this->assertNotNull($song->artwork);
        $this->assertNotNull($song->audio_file_original);
        Storage::disk('public')->assertExists($song->artwork);
        Storage::disk('public')->assertExists($song->audio_file_original);
    }

    public function test_admin_can_update_song_supported_form_fields(): void
    {
        $originalGenre = Genre::factory()->create();
        $updatedGenre = Genre::factory()->create();
        $featuredArtist = Artist::factory()->create([
            'user_id' => $this->createUserWithRole('artist')->id,
        ]);

        $song = Song::factory()->create([
            'artist_id' => $this->artist->id,
            'primary_genre_id' => $originalGenre->id,
            'composer' => null,
            'producer' => null,
            'price' => 0,
            'is_free' => true,
            'is_downloadable' => true,
        ]);

        $response = $this->actingAs($this->admin)->post("/api/admin/songs/{$song->id}", [
            '_method' => 'PUT',
            'title' => 'Updated Admin Song',
            'artist_id' => $this->artist->id,
            'status' => 'draft',
            'explicit' => '1',
            'description' => 'Updated description',
            'composer' => 'Updated Composer',
            'producer' => 'Updated Producer',
            'price' => '5000',
            'is_free' => '0',
            'is_downloadable' => '0',
            'genre_ids' => [$updatedGenre->id],
            'featured_artists' => [$featuredArtist->id],
            'audio_file' => UploadedFile::fake()->create('replacement.mp3', 1024, 'audio/mpeg'),
            'cover_image' => UploadedFile::fake()->image('replacement.jpg', 1200, 1200),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Song updated successfully');

        $song->refresh();
        $this->assertSame('Updated Admin Song', $song->title);
        $this->assertSame('Updated Composer', $song->composer);
        $this->assertSame('Updated Producer', $song->producer);
        $this->assertSame($updatedGenre->id, $song->primary_genre_id);
        $this->assertFalse($song->is_free);
        $this->assertFalse($song->is_downloadable);
        $this->assertNotNull($song->artwork);
        $this->assertNotNull($song->audio_file_original);
    }
}
