<?php

namespace Tests\Feature\Api;

use App\Models\Artist;
use App\Models\Role;
use App\Models\Song;
use App\Models\User;
use App\Notifications\AdminArtistApplicationPendingNotification;
use App\Notifications\AdminCatalogClaimPendingNotification;
use App\Notifications\AdminSongPendingNotification;
use App\Notifications\ArtistApplicationNotification;
use App\Notifications\CatalogClaimStatusNotification;
use App\Notifications\SongModerationNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NotificationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::ADMIN, $this->admin->id);
        $this->admin->clearPermissionCache();
    }

    public function test_artist_application_submission_notifies_applicant_and_reviewers(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $genre = \App\Models\Genre::factory()->create();

        $this->actingAs($user)->postJson('/api/artist/apply', [
            'stage_name' => 'New Voice',
            'bio' => str_repeat('A', 60),
            'primary_genre' => $genre->id,
            'full_name' => 'New Voice Real',
            'phone' => '+256700000100',
            'terms_accepted' => true,
            'artist_agreement_accepted' => true,
        ])->assertCreated();

        Notification::assertSentTo($user, ArtistApplicationNotification::class, function (ArtistApplicationNotification $notification) use ($user) {
            return $notification->toArray($user)['status'] === ArtistApplicationNotification::SUBMITTED;
        });

        Notification::assertSentTo($this->admin, AdminArtistApplicationPendingNotification::class);
    }

    public function test_song_pending_and_published_workflow_sends_notifications(): void
    {
        Notification::fake();

        $artistUser = User::factory()->create([
            'is_artist' => true,
        ]);
        $artistUser->assignRole(Role::ARTIST, $this->admin->id);

        Artist::factory()->create([
            'user_id' => $artistUser->id,
            'can_upload' => true,
            'auto_publish' => false,
            'require_approval' => true,
            'status' => 'active',
        ]);

        $upload = $this->actingAs($artistUser)->post('/api/artist/songs', [
            'title' => 'Moderation Song',
            'audio' => UploadedFile::fake()->create('moderation-song.mp3', 2048, 'audio/mpeg'),
            'is_free' => '1',
            'is_downloadable' => '1',
        ], ['Accept' => 'application/json']);

        $upload->assertCreated();
        $songId = $upload->json('data.id');

        Notification::assertSentTo($artistUser, SongModerationNotification::class, function (SongModerationNotification $notification) use ($artistUser) {
            return $notification->toArray($artistUser)['status'] === SongModerationNotification::PENDING_REVIEW;
        });
        Notification::assertSentTo($this->admin, AdminSongPendingNotification::class);

        $this->actingAs($this->admin)->postJson('/api/admin/songs/bulk-approve', [
            'song_ids' => [$songId],
        ])->assertOk();

        Notification::assertSentTo($artistUser, SongModerationNotification::class, function (SongModerationNotification $notification) use ($artistUser) {
            return $notification->toArray($artistUser)['status'] === SongModerationNotification::APPROVED;
        });
    }

    public function test_catalog_claim_submission_and_approval_notify_claimant_and_reviewers(): void
    {
        Notification::fake();

        $catalogManager = User::factory()->create();
        $catalogManager->assignRole('catalog_manager', $this->admin->id);

        $placeholderOwner = User::factory()->create();
        $placeholderArtist = Artist::factory()->create([
            'user_id' => $placeholderOwner->id,
            'stage_name' => 'Claimable Artist',
            'is_placeholder' => true,
            'claim_status' => 'unclaimed',
            'catalog_manager_user_id' => $catalogManager->id,
            'status' => 'active',
        ]);

        Song::factory()->create([
            'artist_id' => $placeholderArtist->id,
            'user_id' => $catalogManager->id,
            'title' => 'Offline Song',
            'is_claimable' => true,
            'source_type' => 'catalog_submission',
        ]);

        $claimant = User::factory()->create();

        $submit = $this->actingAs($claimant)->postJson('/api/catalog/claim-requests', [
            'artist_id' => $placeholderArtist->id,
            'message' => 'This profile belongs to me.',
        ])->assertCreated();

        $claimId = $submit->json('data.id');

        Notification::assertSentTo($claimant, CatalogClaimStatusNotification::class, function (CatalogClaimStatusNotification $notification) use ($claimant) {
            return $notification->toArray($claimant)['status'] === CatalogClaimStatusNotification::SUBMITTED;
        });
        Notification::assertSentTo($this->admin, AdminCatalogClaimPendingNotification::class);

        $this->actingAs($this->admin)->postJson("/api/admin/catalog/claim-requests/{$claimId}/approve")
            ->assertOk();

        Notification::assertSentTo($claimant, CatalogClaimStatusNotification::class, function (CatalogClaimStatusNotification $notification) use ($claimant) {
            return $notification->toArray($claimant)['status'] === CatalogClaimStatusNotification::APPROVED;
        });
    }
}
