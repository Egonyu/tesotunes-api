<?php

namespace App\Console\Commands;

use App\Models\Song;
use App\Services\Audio\AudioMetadataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class UpdateSongDurations extends Command
{
    public function __construct(
        private readonly AudioMetadataService $audioMetadataService
    ) {
        parent::__construct();
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'songs:update-durations {--force : Update all songs even if they have duration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update duration and available audio metadata for songs with source audio files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $force = $this->option('force');

        $supportsBitrate = Schema::hasColumn('songs', 'bitrate_original');
        $supportsSampleRate = Schema::hasColumn('songs', 'sample_rate');
        $supportsFileSize = Schema::hasColumn('songs', 'file_size_bytes');
        $supportsFormat = Schema::hasColumn('songs', 'file_format');

        $query = Song::query();

        if (! $force) {
            $query->where(function ($q) use ($supportsBitrate, $supportsSampleRate, $supportsFileSize, $supportsFormat) {
                $q->whereNull('duration_seconds')->orWhere('duration_seconds', 0);

                if ($supportsBitrate) {
                    $q->orWhereNull('bitrate_original')->orWhere('bitrate_original', 0);
                }

                if ($supportsSampleRate) {
                    $q->orWhereNull('sample_rate')->orWhere('sample_rate', 0);
                }

                if ($supportsFileSize) {
                    $q->orWhereNull('file_size_bytes')->orWhere('file_size_bytes', 0);
                }

                if ($supportsFormat) {
                    $q->orWhereNull('file_format')->orWhere('file_format', '');
                }
            });
        }

        $songs = $query->whereNotNull('audio_file_original')->get();

        if ($songs->isEmpty()) {
            $this->info('No songs found to update.');

            return 0;
        }

        $this->info("Found {$songs->count()} songs to update.");

        $progressBar = $this->output->createProgressBar($songs->count());
        $progressBar->start();

        $updatedSongs = 0;
        $failed = 0;
        $updatedFields = [
            'duration_seconds' => 0,
            'bitrate_original' => 0,
            'sample_rate' => 0,
            'file_size_bytes' => 0,
            'file_format' => 0,
        ];
        $failureReasons = [];

        foreach ($songs as $song) {
            try {
                $inspection = $this->audioMetadataService->inspectFromStoragePath((string) $song->audio_file_original);
                $metadata = $inspection['metadata'] ?? [];
                $updateData = [];

                if (($force || $this->isMissingInt($song->duration_seconds)) && ($metadata['duration_seconds'] ?? 0) > 0) {
                    $updateData['duration_seconds'] = (int) $metadata['duration_seconds'];
                }

                if ($supportsBitrate && ($force || $this->isMissingInt($song->bitrate_original)) && ($metadata['bitrate_original'] ?? 0) > 0) {
                    $updateData['bitrate_original'] = (int) $metadata['bitrate_original'];
                }

                if ($supportsSampleRate && ($force || $this->isMissingInt($song->sample_rate)) && ($metadata['sample_rate'] ?? 0) > 0) {
                    $updateData['sample_rate'] = (int) $metadata['sample_rate'];
                }

                if ($supportsFileSize && ($force || $this->isMissingInt($song->file_size_bytes)) && ($metadata['file_size_bytes'] ?? 0) > 0) {
                    $updateData['file_size_bytes'] = (int) $metadata['file_size_bytes'];
                }

                if ($supportsFormat && ($force || $this->isMissingString($song->file_format)) && ! empty($metadata['file_format'])) {
                    $updateData['file_format'] = $metadata['file_format'];
                }

                if ($updateData !== []) {
                    $song->update($updateData);
                    $updatedSongs++;

                    foreach (array_keys($updateData) as $field) {
                        $updatedFields[$field]++;
                    }
                } else {
                    $reason = $inspection['failure_reason'] ?? 'no_updatable_metadata';
                    $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
                    $this->newLine();
                    $this->warn("No new metadata extracted for song: {$song->title} (ID: {$song->id}) [reason: {$reason}]");
                    $failed++;
                }
            } catch (\Exception $e) {
                $failureReasons['exception'] = ($failureReasons['exception'] ?? 0) + 1;
                $this->newLine();
                $this->error("Error processing song {$song->id}: {$e->getMessage()}");
                $failed++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Audio metadata update complete!');
        $this->info("Updated songs: {$updatedSongs}");
        $this->info("Failed: {$failed}");
        foreach ($updatedFields as $field => $count) {
            $this->line(" - {$field}: {$count}");
        }
        if ($failureReasons !== []) {
            $this->line('Failure reasons:');
            foreach ($failureReasons as $reason => $count) {
                $this->line(" - {$reason}: {$count}");
            }
        }

        return 0;
    }

    private function isMissingInt(mixed $value): bool
    {
        return $value === null || (int) $value === 0;
    }

    private function isMissingString(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
