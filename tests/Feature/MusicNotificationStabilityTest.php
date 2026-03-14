<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Distribution;
use App\Models\Role;
use App\Models\Song;
use App\Models\User;
use App\Services\DistributionService;
use App\Services\MusicStorageService;
use App\Services\SongService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MusicNotificationStabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_song_moderation_creates_custom_notification_record(): void
    {
        $owner = User::factory()->create();
        $artist = Artist::factory()->create([
            'user_id' => $owner->id,
        ]);
        $moderator = User::factory()->create();
        $adminRole = Role::factory()->admin()->create();
        $moderator->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'is_active' => true,
        ]);
        $song = Song::create([
            'user_id' => $owner->id,
            'uuid' => (string) \Str::uuid(),
            'artist_id' => $artist->id,
            'title' => 'Moderation Song',
            'slug' => 'moderation-song',
            'duration_seconds' => 180,
            'file_size_bytes' => 1234567,
            'status' => 'pending_review',
            'visibility' => 'public',
            'price' => 0,
            'currency' => 'UGX',
            'play_count' => 0,
            'download_count' => 0,
            'like_count' => 0,
            'share_count' => 0,
            'is_downloadable' => true,
        ]);

        $storage = $this->createMock(MusicStorageService::class);
        $service = new SongService($storage);
        $service->moderateSong($song, 'approve', $moderator, 'Ready to publish');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'song_moderated',
            'category' => 'music',
            'notifiable_type' => Song::class,
            'notifiable_id' => $song->id,
        ]);
    }

    public function test_distribution_status_change_creates_custom_notification_record(): void
    {
        $owner = User::factory()->create();
        $artist = Artist::factory()->create([
            'user_id' => $owner->id,
        ]);
        $song = Song::create([
            'user_id' => $owner->id,
            'uuid' => (string) \Str::uuid(),
            'artist_id' => $artist->id,
            'title' => 'Distribution Song',
            'slug' => 'distribution-song',
            'duration_seconds' => 180,
            'file_size_bytes' => 1234567,
            'status' => 'published',
            'visibility' => 'public',
            'price' => 0,
            'currency' => 'UGX',
            'play_count' => 0,
            'download_count' => 0,
            'like_count' => 0,
            'share_count' => 0,
            'is_downloadable' => true,
        ]);
        $distribution = Distribution::create([
            'song_id' => $song->id,
            'artist_id' => $artist->id,
            'platform_code' => 'spotify',
            'platform_name' => 'Spotify',
            'status' => 'pending',
        ]);

        $storage = $this->createMock(MusicStorageService::class);
        $service = new DistributionService($storage);
        $service->updateDistributionStatus($distribution, DistributionService::STATUS_LIVE, [
            'platform_url' => 'https://spotify.test/song',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'distribution_status_change',
            'category' => 'music',
            'notifiable_type' => Distribution::class,
            'notifiable_id' => $distribution->id,
        ]);
    }
}
