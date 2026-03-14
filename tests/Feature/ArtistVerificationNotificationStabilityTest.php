<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistProfile;
use App\Models\Genre;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\ArtistVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtistVerificationNotificationStabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitting_artist_application_creates_admin_notification_in_custom_table(): void
    {
        Storage::fake('private');

        $service = app(ArtistVerificationService::class);
        $genre = $this->createGenre();
        $user = User::factory()->create();
        $admin = $this->createAdminUser();

        $artist = $service->applyForArtistStatus($user, [
            'stage_name' => 'Tracker Artist',
            'bio' => 'Artist bio for tracker',
            'genre_id' => $genre->id,
            'full_name' => 'Tracker Artist',
            'phone' => '+256700000111',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'new_artist_application',
            'category' => 'artist_verification',
            'notifiable_type' => Artist::class,
            'notifiable_id' => $artist->id,
        ]);
    }

    public function test_approve_artist_creates_custom_notification_record(): void
    {
        $service = app(ArtistVerificationService::class);
        $admin = $this->createAdminUser();
        $artist = $this->createPendingArtist();

        $service->approveArtist($artist, $admin, 'Looks good');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $artist->user_id,
            'type' => 'artist_application_approved',
            'category' => 'artist_verification',
            'notifiable_type' => Artist::class,
            'notifiable_id' => $artist->id,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_reject_artist_creates_custom_notification_record(): void
    {
        $service = app(ArtistVerificationService::class);
        $admin = $this->createAdminUser();
        $artist = $this->createPendingArtist();

        $service->rejectArtist($artist, $admin, 'Documents are incomplete');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $artist->user_id,
            'type' => 'artist_application_rejected',
            'category' => 'artist_verification',
            'notifiable_type' => Artist::class,
            'notifiable_id' => $artist->id,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_request_more_info_creates_custom_notification_record(): void
    {
        $service = app(ArtistVerificationService::class);
        $admin = $this->createAdminUser();
        $artist = $this->createPendingArtist();

        $service->requestMoreInfo($artist, $admin, ['national_id_front'], 'Please upload a clearer document');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $artist->user_id,
            'type' => 'artist_application_requires_info',
            'category' => 'artist_verification',
            'notifiable_type' => Artist::class,
            'notifiable_id' => $artist->id,
            'actor_id' => $admin->id,
        ]);
    }

    protected function createAdminUser(): User
    {
        $admin = User::factory()->create([
            'is_active' => true,
        ]);

        $role = Role::query()->firstOrCreate(
            ['name' => Role::ADMIN],
            [
                'display_name' => 'Admin',
                'description' => 'Administrator',
                'is_active' => true,
                'priority' => 5,
            ]
        );

        $admin->roles()->syncWithoutDetaching([
            $role->id => [
                'assigned_at' => now(),
                'is_active' => true,
            ],
        ]);

        return $admin;
    }

    protected function createPendingArtist(): Artist
    {
        $user = User::factory()->create([
            'application_status' => 'pending',
        ]);

        $artist = Artist::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'verification_status' => 'pending',
            'is_verified' => false,
        ]);

        ArtistProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'artist_id' => $artist->id,
                'stage_name' => $artist->stage_name,
                'verification_status' => 'pending',
                'verification_documents' => [],
                'is_active' => true,
            ]
        );

        return $artist->fresh();
    }

    protected function createGenre(): Genre
    {
        return Genre::create([
            'uuid' => (string) \Str::uuid(),
            'name' => 'Tracker Genre',
            'slug' => 'tracker-genre',
            'description' => 'Tracker genre',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }
}
