<?php

namespace Tests\Feature\Catalog;

use App\Models\Album;
use App\Models\ISRCCode;
use App\Models\Song;
use App\Models\User;
use App\Services\Audio\AudioMetadataService;
use App\Services\Music\ISRCService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SongMetadataBackfillAndIsrcCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_song_isrc_code_falls_back_to_legacy_isrc_column(): void
    {
        $song = Song::factory()->create([
            'isrc_code' => null,
        ]);

        DB::table('songs')
            ->where('id', $song->id)
            ->update([
                'isrc' => 'UG-MUS-26-00001',
                'isrc_code' => null,
            ]);

        $song->refresh();

        $this->assertSame('UG-MUS-26-00001', $song->isrc_code);
    }

    public function test_setting_isrc_code_keeps_legacy_song_column_in_sync(): void
    {
        $song = Song::factory()->create([
            'isrc' => null,
            'isrc_code' => null,
        ]);

        $song->update([
            'isrc_code' => 'UG-MUS-26-00002',
        ]);

        $song->refresh();

        $this->assertSame('UG-MUS-26-00002', $song->isrc_code);
        $this->assertSame('UG-MUS-26-00002', $song->getRawOriginal('isrc'));
    }

    public function test_update_song_durations_command_backfills_extended_audio_metadata(): void
    {
        $song = Song::factory()->create([
            'audio_file_original' => 'songs/audio/test-track.mp3',
            'duration_seconds' => 0,
            'file_size_bytes' => null,
            'file_format' => null,
            'bitrate_original' => null,
            'sample_rate' => null,
        ]);

        $mock = Mockery::mock(AudioMetadataService::class);
        $mock->shouldReceive('inspectFromStoragePath')
            ->once()
            ->with('songs/audio/test-track.mp3')
            ->andReturn([
                'source_path' => 'songs/audio/test-track.mp3',
                'resolved_path' => '/tmp/test-track.mp3',
                'disk' => 'public',
                'temporary_file' => false,
                'exists' => true,
                'metadata' => [
                    'duration_seconds' => 245,
                    'duration_formatted' => '4:05',
                    'bitrate_original' => 320000,
                    'sample_rate' => 44100,
                    'file_size_bytes' => 9876543,
                    'file_format' => 'mp3',
                ],
                'extracted_by' => 'getid3',
                'failure_reason' => null,
                'extractors' => [],
            ]);

        $this->app->instance(AudioMetadataService::class, $mock);

        $this->artisan('songs:update-durations')
            ->expectsOutputToContain('Updated songs: 1')
            ->expectsOutputToContain(' - duration_seconds: 1')
            ->expectsOutputToContain(' - bitrate_original: 1')
            ->expectsOutputToContain(' - sample_rate: 1')
            ->assertExitCode(0);

        $song->refresh();

        $this->assertSame(245, $song->duration_seconds);
        $this->assertSame(320000, $song->bitrate_original);
        $this->assertSame(44100, $song->sample_rate);
        $this->assertSame(9876543, $song->file_size_bytes);
        $this->assertSame('mp3', $song->file_format);
    }

    public function test_isrc_service_registers_codes_using_normalized_schema(): void
    {
        $song = Song::factory()->create([
            'isrc' => null,
            'isrc_code' => null,
        ]);
        $song->forceFill([
            'status' => 'published',
            'distribution_status' => 'approved',
            'approved_at' => now(),
        ]);

        $isrcCode = app(ISRCService::class)->generate($song);

        $record = ISRCCode::query()->where('isrc_code', $isrcCode)->first();

        $this->assertNotNull($record);
        $this->assertSame($isrcCode, $record->isrc_code);
        $this->assertSame($isrcCode, $record->getRawOriginal('code'));
        $this->assertSame('pending', $record->status);
        $this->assertSame($song->id, $record->song_id);
        $this->assertSame($song->artist_id, $record->artist_id);
    }

    public function test_song_cannot_assign_isrc_while_it_is_not_authorized(): void
    {
        $song = Song::factory()->create([
            'duration_seconds' => 245,
            'audio_file_original' => 'songs/audio/test-track.mp3',
            'isrc_code' => null,
        ]);
        $song->forceFill([
            'status' => 'draft',
            'distribution_status' => 'not_submitted',
            'approved_at' => null,
        ]);

        $this->assertFalse($song->canAssignIsrc());
        $this->assertContains('not_authorized', $song->getIsrcAssignmentBlockers());
    }

    public function test_song_can_assign_isrc_once_release_is_approved(): void
    {
        $song = Song::factory()->create([
            'duration_seconds' => 245,
            'audio_file_original' => 'songs/audio/test-track.mp3',
            'isrc_code' => null,
        ]);
        $song->forceFill([
            'status' => 'published',
            'distribution_status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->assertTrue($song->canAssignIsrc());
    }

    public function test_assign_to_song_rejects_ineligible_song(): void
    {
        $song = Song::factory()->create([
            'duration_seconds' => 245,
            'audio_file_original' => 'songs/audio/test-track.mp3',
            'isrc_code' => null,
        ]);
        $song->forceFill([
            'status' => 'draft',
            'distribution_status' => 'not_submitted',
            'approved_at' => null,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Song is not eligible for ISRC assignment');

        app(ISRCService::class)->assignToSong($song);
    }

    public function test_admin_can_generate_isrc_for_eligible_song_via_api(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin);

        $song = Song::factory()->create([
            'isrc_code' => null,
            'audio_file_original' => 'songs/audio/test-track.mp3',
            'duration_seconds' => 245,
            'status' => 'published',
            'approved_at' => now(),
        ]);

        $response = $this->postJson("/api/songs/{$song->id}/generate-isrc");

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.song_id', $song->id);

        $song->refresh();

        $this->assertNotNull($song->isrc_code);
    }

    public function test_ineligible_song_returns_blockers_from_generate_isrc_endpoint(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $song = Song::factory()->create([
            'user_id' => $owner->id,
            'isrc_code' => null,
            'audio_file_original' => null,
            'duration_seconds' => 0,
            'status' => 'draft',
            'approved_at' => null,
        ]);

        $response = $this->postJson("/api/songs/{$song->id}/generate-isrc");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Song is not eligible for ISRC assignment.')
            ->assertJsonFragment(['missing_duration'])
            ->assertJsonFragment(['not_authorized']);
    }

    public function test_admin_bulk_approve_assigns_isrc_and_reports_counts(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin);

        $song = Song::factory()->create([
            'isrc_code' => null,
            'audio_file_original' => 'songs/audio/test-track.mp3',
            'duration_seconds' => 245,
            'status' => 'pending',
            'approved_at' => null,
            'published_at' => null,
        ]);

        $response = $this->postJson('/api/admin/songs/bulk-approve', [
            'song_ids' => [$song->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.approved_count', 1)
            ->assertJsonPath('data.isrc_assigned_count', 1)
            ->assertJsonPath('data.isrc_already_assigned_count', 0)
            ->assertJsonPath('data.isrc_blocked_count', 0);

        $song->refresh();

        $this->assertSame('published', $song->status);
        $this->assertSame('approved', $song->distribution_status);
        $this->assertNotNull($song->approved_at);
        $this->assertNotNull($song->published_at);
        $this->assertNotNull($song->isrc_code);
    }

    public function test_audio_metadata_service_inspection_reports_missing_source_reason(): void
    {
        $inspection = app(AudioMetadataService::class)->inspectFromStoragePath('songs/audio/does-not-exist.mp3');

        $this->assertFalse($inspection['exists']);
        $this->assertSame('missing_source', $inspection['failure_reason']);
        $this->assertSame(0, $inspection['metadata']['duration_seconds']);
    }

    public function test_update_song_durations_command_reports_diagnostic_failure_reasons(): void
    {
        $song = Song::factory()->create([
            'audio_file_original' => 'songs/audio/missing-track.mp3',
            'duration_seconds' => 0,
            'file_size_bytes' => null,
            'file_format' => null,
            'bitrate_original' => null,
            'sample_rate' => null,
        ]);

        $mock = \Mockery::mock(AudioMetadataService::class);
        $mock->shouldReceive('inspectFromStoragePath')
            ->once()
            ->with('songs/audio/missing-track.mp3')
            ->andReturn([
                'source_path' => 'songs/audio/missing-track.mp3',
                'resolved_path' => null,
                'disk' => 'public',
                'temporary_file' => false,
                'exists' => false,
                'metadata' => [
                    'duration_seconds' => 0,
                    'duration_formatted' => '0:00',
                    'bitrate_original' => null,
                    'sample_rate' => null,
                    'file_size_bytes' => null,
                    'file_format' => null,
                ],
                'extracted_by' => null,
                'failure_reason' => 'missing_source',
                'extractors' => [],
            ]);

        $this->app->instance(AudioMetadataService::class, $mock);

        $this->artisan('songs:update-durations')
            ->expectsOutputToContain('Failed: 1')
            ->expectsOutputToContain('Failure reasons:')
            ->expectsOutputToContain(' - missing_source: 1')
            ->assertExitCode(0);

        $song->refresh();
        $this->assertSame(0, $song->duration_seconds);
    }

    public function test_album_batch_isrc_generation_persists_codes_onto_songs(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin);

        $album = Album::factory()->create();

        $firstSong = Song::factory()->create([
            'album_id' => $album->id,
            'artist_id' => $album->artist_id,
            'isrc_code' => null,
            'audio_file_original' => 'songs/audio/album-track-1.mp3',
            'duration_seconds' => 200,
            'status' => 'published',
            'distribution_status' => 'approved',
            'approved_at' => now(),
        ]);

        $secondSong = Song::factory()->create([
            'album_id' => $album->id,
            'artist_id' => $album->artist_id,
            'isrc_code' => null,
            'audio_file_original' => 'songs/audio/album-track-2.mp3',
            'duration_seconds' => 240,
            'status' => 'published',
            'distribution_status' => 'approved',
            'approved_at' => now(),
        ]);

        $response = $this->postJson("/api/albums/{$album->id}/generate-isrc-batch");

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_songs', 2)
            ->assertJsonPath('data.generated', 2)
            ->assertJsonCount(2, 'data.codes');

        $firstSong->refresh();
        $secondSong->refresh();

        $this->assertNotNull($firstSong->isrc_code);
        $this->assertNotNull($secondSong->isrc_code);
        $this->assertDatabaseHas('isrc_codes', ['song_id' => $firstSong->id, 'isrc_code' => $firstSong->isrc_code]);
        $this->assertDatabaseHas('isrc_codes', ['song_id' => $secondSong->id, 'isrc_code' => $secondSong->isrc_code]);
    }

    public function test_bulk_isrc_operation_persists_codes_onto_songs(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin);

        $firstSong = Song::factory()->create([
            'isrc_code' => null,
            'audio_file_original' => 'songs/audio/bulk-track-1.mp3',
            'duration_seconds' => 181,
            'status' => 'published',
            'distribution_status' => 'approved',
            'approved_at' => now(),
        ]);

        $secondSong = Song::factory()->create([
            'isrc_code' => null,
            'audio_file_original' => 'songs/audio/bulk-track-2.mp3',
            'duration_seconds' => 207,
            'status' => 'published',
            'distribution_status' => 'approved',
            'approved_at' => now(),
        ]);

        $response = $this->postJson('/api/isrc/bulk', [
            'song_ids' => [$firstSong->id, $secondSong->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.requested', 2)
            ->assertJsonPath('data.eligible', 2)
            ->assertJsonPath('data.generated', 2)
            ->assertJsonCount(2, 'data.codes');

        $firstSong->refresh();
        $secondSong->refresh();

        $this->assertNotNull($firstSong->isrc_code);
        $this->assertNotNull($secondSong->isrc_code);
        $this->assertDatabaseHas('isrc_codes', ['song_id' => $firstSong->id, 'isrc_code' => $firstSong->isrc_code]);
        $this->assertDatabaseHas('isrc_codes', ['song_id' => $secondSong->id, 'isrc_code' => $secondSong->isrc_code]);
    }

    public function test_song_audio_quality_helpers_use_audio_quality_score(): void
    {
        $studioSong = Song::factory()->create(['audio_quality_score' => 97]);
        $highSong = Song::factory()->create(['audio_quality_score' => 88]);
        $standardSong = Song::factory()->create(['audio_quality_score' => 74]);
        $mobileSong = Song::factory()->create(['audio_quality_score' => 55]);

        $this->assertSame('🔊 Studio Quality', $studioSong->audio_quality_badge);
        $this->assertSame('🎵 High Quality', $highSong->audio_quality_badge);
        $this->assertSame('🎶 Standard', $standardSong->audio_quality_badge);
        $this->assertSame('📱 Mobile Optimized', $mobileSong->audio_quality_badge);

        $this->assertTrue(Song::query()->byAudioQuality('studio')->whereKey($studioSong->id)->exists());
        $this->assertTrue(Song::query()->byAudioQuality('high')->whereKey($highSong->id)->exists());
        $this->assertTrue(Song::query()->byAudioQuality('standard')->whereKey($standardSong->id)->exists());
        $this->assertTrue(Song::query()->byAudioQuality('compressed')->whereKey($mobileSong->id)->exists());
    }

    public function test_song_approve_and_reject_keep_release_state_in_sync(): void
    {
        $admin = User::factory()->create();

        $approvedSong = Song::factory()->create([
            'status' => 'pending',
            'distribution_status' => 'pending_review',
            'approved_at' => null,
            'published_at' => null,
            'audio_file_original' => 'songs/audio/sync-approve.mp3',
            'duration_seconds' => 215,
            'isrc_code' => null,
        ]);

        $approvedSong->approve($admin, 'Approved for release');
        $approvedSong->refresh();

        $this->assertSame('published', $approvedSong->status);
        $this->assertSame('approved', $approvedSong->distribution_status);
        $this->assertNotNull($approvedSong->approved_at);
        $this->assertNotNull($approvedSong->published_at);

        $rejectedSong = Song::factory()->create([
            'status' => 'pending_review',
            'distribution_status' => 'pending_review',
            'approved_at' => null,
        ]);

        $rejectedSong->reject($admin, 'Rights metadata incomplete');
        $rejectedSong->refresh();

        $this->assertSame('rejected', $rejectedSong->status);
        $this->assertSame('rejected', $rejectedSong->distribution_status);
        $this->assertSame('Rights metadata incomplete', $rejectedSong->rejection_reason);
    }

    public function test_song_approve_does_not_auto_assign_isrc_when_auto_generate_is_disabled(): void
    {
        config()->set('music.isrc.auto_generate', false);

        $admin = User::factory()->create();

        $song = Song::factory()->create([
            'status' => 'pending',
            'distribution_status' => 'pending_review',
            'approved_at' => null,
            'published_at' => null,
            'audio_file_original' => 'songs/audio/no-auto-isrc.mp3',
            'duration_seconds' => 199,
            'isrc_code' => null,
        ]);

        $song->approve($admin, 'Approved with auto-generate disabled');
        $song->refresh();

        $this->assertSame('published', $song->status);
        $this->assertSame('approved', $song->distribution_status);
        $this->assertNull($song->isrc_code);
    }

    public function test_song_approve_auto_assigns_isrc_when_auto_generate_is_enabled(): void
    {
        config()->set('music.isrc.auto_generate', true);

        $admin = User::factory()->create();

        $song = Song::factory()->create([
            'status' => 'pending',
            'distribution_status' => 'pending_review',
            'approved_at' => null,
            'published_at' => null,
            'audio_file_original' => 'songs/audio/auto-isrc.mp3',
            'duration_seconds' => 211,
            'isrc_code' => null,
        ]);

        $song->approve($admin, 'Approved with auto-generate enabled');
        $song->refresh();

        $this->assertSame('published', $song->status);
        $this->assertSame('approved', $song->distribution_status);
        $this->assertNotNull($song->isrc_code);
    }
}
