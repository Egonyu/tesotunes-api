<?php

namespace Tests\Feature\Audio;

use App\Jobs\Audio\TranscodeToHlsJob;
use App\Models\Artist;
use App\Models\Song;
use App\Models\User;
use App\Services\Audio\FFmpegService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Feature\Api\ImageUpload\CreatesUsersWithRoles;
use Tests\TestCase;

class HlsPipelineTest extends TestCase
{
    use CreatesUsersWithRoles, DatabaseTransactions;

    public function test_song_upload_dispatches_the_hls_transcode_job(): void
    {
        Queue::fake();
        config(['filesystems.default' => 'public']);
        Storage::fake('public');

        $artistUser = $this->createUserWithRole('artist');
        $artist = Artist::factory()->create(['user_id' => $artistUser->id, 'can_upload' => true]);

        $this->actingAs($artistUser)->post('/api/artist/songs', [
            'title' => 'Adaptive Anthem',
            'audio' => UploadedFile::fake()->create('song.mp3', 2048, 'audio/mpeg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        Queue::assertPushed(TranscodeToHlsJob::class, function (TranscodeToHlsJob $job) {
            return $job->song->title === 'Adaptive Anthem';
        });
    }

    public function test_hls_job_skips_gracefully_when_ffmpeg_is_unavailable(): void
    {
        $song = $this->publishedSong();

        $ffmpeg = Mockery::mock(FFmpegService::class);
        $ffmpeg->shouldReceive('isAvailable')->once()->andReturnFalse();

        (new TranscodeToHlsJob($song))->handle($ffmpeg);

        $song->refresh();
        $this->assertSame('skipped_no_ffmpeg', $song->processing_status['hls'] ?? null);
        $this->assertNull($song->hls_master_path);
        $this->assertSame('published', $song->status, 'HLS outcome must never touch the publish lifecycle');
    }

    public function test_hls_job_builds_ladder_and_master_playlist(): void
    {
        config(['filesystems.default' => 'public']);
        Storage::fake('public');

        $song = $this->publishedSong();
        Storage::disk('public')->put($song->audio_file_original, 'fake-audio-bytes');

        $ffmpeg = Mockery::mock(FFmpegService::class);
        $ffmpeg->shouldReceive('isAvailable')->once()->andReturnTrue();
        $ffmpeg->shouldReceive('generateHlsRendition')
            ->times(3)
            ->andReturnUsing(function (string $input, string $outputDir) {
                if (! is_dir($outputDir)) {
                    mkdir($outputDir, 0755, true);
                }
                file_put_contents($outputDir.DIRECTORY_SEPARATOR.'index.m3u8', "#EXTM3U\n");
                file_put_contents($outputDir.DIRECTORY_SEPARATOR.'seg_000.ts', 'segment');

                return true;
            });

        (new TranscodeToHlsJob($song))->handle($ffmpeg);

        $song->refresh();
        $this->assertSame("hls/songs/{$song->id}/master.m3u8", $song->hls_master_path);
        $this->assertNotNull($song->hls_generated_at);
        $this->assertSame('completed', $song->processing_status['hls'] ?? null);

        Storage::disk('public')->assertExists("hls/songs/{$song->id}/master.m3u8");
        Storage::disk('public')->assertExists("hls/songs/{$song->id}/320/index.m3u8");
        Storage::disk('public')->assertExists("hls/songs/{$song->id}/64/seg_000.ts");

        $master = Storage::disk('public')->get("hls/songs/{$song->id}/master.m3u8");
        $this->assertStringContainsString('#EXT-X-STREAM-INF:BANDWIDTH=352000', $master);
        $this->assertStringContainsString('320/index.m3u8', $master);
    }

    public function test_streaming_access_exposes_hls_master_url_with_progressive_fallback(): void
    {
        $song = $this->publishedSong();

        $withoutHls = $this->getJson("/api/songs/{$song->slug}")->assertOk();
        $this->assertNull($withoutHls->json('data.hls_master_url'));
        $this->assertNotNull($withoutHls->json('data.stream_url'), 'progressive fallback must always be present');

        $song->forceFill([
            'hls_master_path' => "hls/songs/{$song->id}/master.m3u8",
            'hls_generated_at' => now(),
        ])->save();

        $withHls = $this->getJson("/api/songs/{$song->slug}")->assertOk();
        $this->assertNotNull($withHls->json('data.hls_master_url'));
        $this->assertStringContainsString("hls/songs/{$song->id}/master.m3u8", $withHls->json('data.hls_master_url'));
        $this->assertNotNull($withHls->json('data.stream_url'));
    }

    public function test_backfill_command_queues_only_songs_without_a_ladder(): void
    {
        Queue::fake();

        $needsLadder = $this->publishedSong();
        $alreadyDone = $this->publishedSong();
        $alreadyDone->forceFill(['hls_master_path' => "hls/songs/{$alreadyDone->id}/master.m3u8"])->save();

        $this->artisan('hls:backfill', ['--limit' => 10])
            ->assertSuccessful();

        Queue::assertPushed(TranscodeToHlsJob::class, fn (TranscodeToHlsJob $job) => $job->song->id === $needsLadder->id);
        Queue::assertNotPushed(TranscodeToHlsJob::class, fn (TranscodeToHlsJob $job) => $job->song->id === $alreadyDone->id);
    }

    private function publishedSong(): Song
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create(['user_id' => $user->id, 'status' => 'approved']);

        return Song::factory()->create([
            'artist_id' => $artist->id,
            'user_id' => $user->id,
            'status' => 'published',
            'visibility' => 'public',
            'audio_file_original' => 'songs/audio/test-original.mp3',
        ]);
    }
}
