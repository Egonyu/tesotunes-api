<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\CatalogClaimRequest;
use App\Models\CatalogSubmission;
use App\Models\Song;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogIntakeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $catalogManager;

    private User $admin;

    private User $artistUser;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');

        $this->seed(RolePermissionSeeder::class);

        $this->catalogManager = User::factory()->create();
        $this->catalogManager->assignRole('catalog_manager', $this->catalogManager->id);
        $this->catalogManager->clearPermissionCache();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin', $this->admin->id);
        $this->admin->clearPermissionCache();

        $this->artistUser = User::factory()->create([
            'is_artist' => true,
        ]);
        $this->artistUser->assignRole('artist', $this->admin->id);
        $this->artistUser->clearPermissionCache();

        $this->artist = Artist::factory()->create([
            'user_id' => $this->artistUser->id,
            'can_upload' => true,
            'status' => 'active',
            'total_songs_count' => 1,
        ]);
    }

    public function test_delete_song_releases_slug_and_allows_same_title_reupload(): void
    {
        Song::withTrashed()->where('slug', 'teete')->forceDelete();

        $song = Song::factory()->create([
            'artist_id' => $this->artist->id,
            'user_id' => $this->artistUser->id,
            'title' => 'Teete',
            'slug' => 'teete',
        ]);

        $this->actingAs($this->artistUser)
            ->deleteJson("/api/artist/songs/{$song->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Song deleted successfully.');

        $deletedSong = Song::withTrashed()->findOrFail($song->id);
        $this->assertNotSame('teete', $deletedSong->slug);
        $this->assertStringStartsWith('teete-deleted-', $deletedSong->slug);

        $audio = UploadedFile::fake()->create('teete.mp3', 1024, 'audio/mpeg');

        $this->actingAs($this->artistUser)
            ->post('/api/artist/songs', [
                'title' => 'Teete',
                'audio' => $audio,
                'is_free' => '1',
                'is_downloadable' => '1',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Teete');

        $this->assertDatabaseHas('songs', [
            'artist_id' => $this->artist->id,
            'title' => 'Teete',
            'slug' => 'teete',
            'deleted_at' => null,
        ]);
    }

    public function test_catalog_manager_can_bulk_upload_multi_artist_csv_and_reuse_placeholder_artist(): void
    {
        $csv = UploadedFile::fake()->createWithContent('catalog.csv', implode("\n", [
            'audio_filename,artist_name,song_title,genre,featured_artists',
            'first-song.mp3,Offline Star,Song One,Teso Hip-Hop,"Guest One, Guest Two"',
            'second-song.mp3,Offline Star,Song Two,Teso Hip-Hop,',
            'third-song.mp3,Another Voice,Song Three,Dancehall,',
        ]));

        $response = $this->actingAs($this->catalogManager)->post('/api/catalog/submissions', [
            'csv_file' => $csv,
            'audio_files' => [
                UploadedFile::fake()->create('first-song.mp3', 2048, 'audio/mpeg'),
                UploadedFile::fake()->create('second-song.mp3', 2048, 'audio/mpeg'),
                UploadedFile::fake()->create('third-song.mp3', 2048, 'audio/mpeg'),
            ],
            'source_name' => 'Local intake',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'processed')
            ->assertJsonPath('data.total_items', 3)
            ->assertJsonPath('data.processed_items', 3);

        $submission = CatalogSubmission::query()->firstOrFail();
        $this->assertCount(3, $submission->items);
        $this->assertSame(
            2,
            Artist::query()
                ->whereIn('stage_name', ['Offline Star', 'Another Voice'])
                ->where('is_placeholder', true)
                ->count()
        );
        $this->assertSame(1, Artist::query()->where('is_placeholder', true)->where('stage_name', 'Offline Star')->count());
        $this->assertSame(3, Song::query()->where('source_type', 'catalog_submission')->count());
        $this->assertDatabaseHas('songs', [
            'title' => 'Song One',
            'source_type' => 'catalog_submission',
            'is_claimable' => true,
        ]);
        $this->assertDatabaseHas('songs', [
            'title' => 'Song Two',
            'source_type' => 'catalog_submission',
            'is_claimable' => true,
        ]);
    }

    public function test_catalog_submission_marks_row_failed_when_audio_file_is_missing(): void
    {
        $csv = UploadedFile::fake()->createWithContent('catalog.csv', implode("\n", [
            'audio_filename,artist_name,song_title',
            'available.mp3,Offline Star,Song One',
            'missing.mp3,Offline Star,Song Two',
        ]));

        $response = $this->actingAs($this->catalogManager)->post('/api/catalog/submissions', [
            'csv_file' => $csv,
            'audio_files' => [
                UploadedFile::fake()->create('available.mp3', 2048, 'audio/mpeg'),
            ],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.failed_items', 1);

        $failedItem = CatalogSubmission::query()->firstOrFail()
            ->items()
            ->where('audio_filename', 'missing.mp3')
            ->firstOrFail();

        $this->assertSame('failed', $failedItem->status);
        $this->assertSame('No uploaded audio file matches this row.', $failedItem->validation_errors['audio_filename'][0]);
    }

    public function test_user_can_claim_placeholder_artist_and_admin_can_approve_claim(): void
    {
        $placeholderOwner = User::factory()->create();
        $placeholderArtist = Artist::factory()->create([
            'user_id' => $placeholderOwner->id,
            'name' => 'Claim Later Artist',
            'stage_name' => 'Claim Later Artist',
            'is_placeholder' => true,
            'claim_status' => 'unclaimed',
            'catalog_manager_user_id' => $this->catalogManager->id,
            'status' => 'active',
        ]);

        $song = Song::factory()->create([
            'artist_id' => $placeholderArtist->id,
            'user_id' => $this->catalogManager->id,
            'title' => 'Claim Me',
            'is_claimable' => true,
            'source_type' => 'catalog_submission',
        ]);

        $claimant = User::factory()->create();

        $claimResponse = $this->actingAs($claimant)->postJson('/api/catalog/claim-requests', [
            'artist_id' => $placeholderArtist->id,
            'song_ids' => [$song->id],
            'phone_number' => '+256700000001',
            'message' => 'This is my artist profile.',
            'evidence' => ['manager referral'],
        ]);

        $claimResponse->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $claim = CatalogClaimRequest::query()->firstOrFail();

        $this->actingAs($this->admin)->postJson("/api/admin/catalog/claim-requests/{$claim->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $placeholderArtist->refresh();
        $song->refresh();
        $claimant->refresh();

        $this->assertSame($claimant->id, $placeholderArtist->user_id);
        $this->assertFalse((bool) $placeholderArtist->is_placeholder);
        $this->assertSame('claimed', $placeholderArtist->claim_status);
        $this->assertSame($claimant->id, $song->user_id);
        $this->assertFalse((bool) $song->is_claimable);
        $this->assertTrue((bool) $claimant->is_artist);
        $this->assertTrue($claimant->hasRole('artist'));
    }

    public function test_catalog_permissions_are_restricted_to_catalog_manager_and_admin_reviewers(): void
    {
        $regularUser = User::factory()->create();

        $csv = UploadedFile::fake()->createWithContent('catalog.csv', implode("\n", [
            'audio_filename,artist_name,song_title',
            'first-song.mp3,Offline Star,Song One',
        ]));

        $this->actingAs($regularUser)->post('/api/catalog/submissions', [
            'csv_file' => $csv,
            'audio_files' => [
                UploadedFile::fake()->create('first-song.mp3', 2048, 'audio/mpeg'),
            ],
        ], ['Accept' => 'application/json'])->assertForbidden();

        $claim = CatalogClaimRequest::factory()->create([
            'artist_id' => Artist::factory()->create([
                'is_placeholder' => true,
                'catalog_manager_user_id' => $this->catalogManager->id,
            ])->id,
            'claimant_user_id' => User::factory()->create()->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->catalogManager)
            ->postJson("/api/admin/catalog/claim-requests/{$claim->id}/approve")
            ->assertForbidden();
    }

    public function test_public_can_search_claimable_placeholder_artists(): void
    {
        Artist::factory()->create([
            'name' => 'Claimable Voice',
            'stage_name' => 'Claimable Voice',
            'status' => 'active',
            'is_placeholder' => true,
            'claim_status' => 'unclaimed',
        ]);

        Artist::factory()->create([
            'name' => 'Already Claimed',
            'stage_name' => 'Already Claimed',
            'status' => 'active',
            'is_placeholder' => true,
            'claim_status' => 'claimed',
        ]);

        Artist::factory()->create([
            'name' => 'Regular Artist',
            'stage_name' => 'Regular Artist',
            'status' => 'active',
            'is_placeholder' => false,
            'claim_status' => 'claimed',
        ]);

        $response = $this->getJson('/api/catalog/claimable-artists?claimable_only=1&search=Claimable');

        $response->assertOk();
        $artists = $response->json('data');

        $this->assertCount(1, $artists);
        $this->assertSame('Claimable Voice', $artists[0]['name']);
        $this->assertTrue($artists[0]['is_placeholder']);
        $this->assertSame('unclaimed', $artists[0]['claim_status']);
    }

    public function test_claimant_can_list_their_catalog_claim_requests(): void
    {
        $claimant = User::factory()->create();
        $otherUser = User::factory()->create();

        $artist = Artist::factory()->create([
            'is_placeholder' => true,
            'claim_status' => 'unclaimed',
            'status' => 'active',
        ]);

        CatalogClaimRequest::factory()->create([
            'claimant_user_id' => $claimant->id,
            'artist_id' => $artist->id,
            'status' => 'pending',
            'message' => 'This profile belongs to me.',
        ]);

        CatalogClaimRequest::factory()->create([
            'claimant_user_id' => $otherUser->id,
            'artist_id' => $artist->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($claimant)->getJson('/api/catalog/claim-requests');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('pending', $response->json('data.data.0.status'));
        $this->assertSame($artist->id, $response->json('data.data.0.artist.id'));
    }
}
