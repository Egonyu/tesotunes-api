<?php

namespace App\Console\Commands;

use App\Models\Song;
use App\Services\Audio\AudioMetadataService;
use Illuminate\Console\Command;

class InspectSongAudioMetadata extends Command
{
    protected $signature = 'songs:inspect-audio-metadata
        {song? : Song ID to inspect}
        {--path= : Storage path to inspect directly instead of a song}
        {--disk= : Preferred storage disk when inspecting a storage path}';

    protected $description = 'Inspect audio metadata extraction for a song or stored audio path';

    public function __construct(
        private readonly AudioMetadataService $audioMetadataService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $songId = $this->argument('song');
        $pathOption = $this->option('path');
        $diskOption = $this->option('disk');

        if (! $songId && ! $pathOption) {
            $this->error('Provide either a song ID or --path=');

            return self::FAILURE;
        }

        $song = null;
        $path = $pathOption;

        if ($songId) {
            $song = Song::find($songId);

            if (! $song) {
                $this->error("Song {$songId} not found.");

                return self::FAILURE;
            }

            $path = $song->audio_file_original;

            if (! $path) {
                $this->error("Song {$song->id} has no audio_file_original.");

                return self::FAILURE;
            }
        }

        $inspection = $this->audioMetadataService->inspectFromStoragePath((string) $path, $diskOption ?: null);

        if ($song) {
            $this->info("Song #{$song->id}: {$song->title}");
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['Source path', $inspection['source_path'] ?? ''],
                ['Resolved path', $inspection['resolved_path'] ?? ''],
                ['Disk', $inspection['disk'] ?? ''],
                ['Exists', ($inspection['exists'] ?? false) ? 'yes' : 'no'],
                ['Temporary file', ($inspection['temporary_file'] ?? false) ? 'yes' : 'no'],
                ['Extracted by', $inspection['extracted_by'] ?? ''],
                ['Failure reason', $inspection['failure_reason'] ?? ''],
                ['Duration seconds', (string) (($inspection['metadata']['duration_seconds'] ?? 0))],
                ['Duration formatted', (string) (($inspection['metadata']['duration_formatted'] ?? ''))],
                ['Bitrate original', (string) (($inspection['metadata']['bitrate_original'] ?? ''))],
                ['Sample rate', (string) (($inspection['metadata']['sample_rate'] ?? ''))],
                ['File size bytes', (string) (($inspection['metadata']['file_size_bytes'] ?? ''))],
                ['File format', (string) (($inspection['metadata']['file_format'] ?? ''))],
            ]
        );

        $extractorRows = [];
        foreach (($inspection['extractors'] ?? []) as $name => $result) {
            $extractorRows[] = [
                $name,
                ($result['supported'] ?? false) ? 'yes' : 'no',
                ($result['success'] ?? false) ? 'yes' : 'no',
                $result['error'] ?? '',
            ];
        }

        $this->newLine();
        $this->table(['Extractor', 'Supported', 'Success', 'Error'], $extractorRows);

        return self::SUCCESS;
    }
}
