<?php

namespace App\Services\Audio;

use App\Helpers\StorageHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AudioMetadataService
{
    public function extractFromStoragePath(string $path, ?string $preferredDisk = null): array
    {
        return $this->inspectFromStoragePath($path, $preferredDisk)['metadata'];
    }

    public function extractFromAbsolutePath(string $path): array
    {
        return $this->inspectFromAbsolutePath($path)['metadata'];
    }

    public function inspectFromStoragePath(string $path, ?string $preferredDisk = null): array
    {
        [$localPath, $temporaryPath, $diskName] = $this->materializeStoragePath($path, $preferredDisk);

        if ($localPath === null) {
            return [
                'source_path' => $path,
                'resolved_path' => null,
                'disk' => $diskName,
                'temporary_file' => false,
                'exists' => false,
                'metadata' => $this->emptyMetadata(),
                'extracted_by' => null,
                'failure_reason' => 'missing_source',
                'extractors' => [
                    'getid3' => $this->unsupportedProbeResult(\getID3::class),
                    'php_ffprobe' => $this->unsupportedProbeResult(\FFMpeg\FFProbe::class),
                    'shell_ffprobe' => $this->shellSupportProbeResult(),
                ],
            ];
        }

        try {
            $inspection = $this->inspectFromAbsolutePath($localPath);
            $inspection['source_path'] = $path;
            $inspection['disk'] = $diskName;
            $inspection['temporary_file'] = $temporaryPath !== null;

            return $inspection;
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public function inspectFromAbsolutePath(string $path): array
    {
        if (! is_file($path)) {
            return [
                'source_path' => $path,
                'resolved_path' => $path,
                'disk' => null,
                'temporary_file' => false,
                'exists' => false,
                'metadata' => $this->emptyMetadata(),
                'extracted_by' => null,
                'failure_reason' => 'file_not_found',
                'extractors' => [
                    'getid3' => $this->unsupportedProbeResult(\getID3::class),
                    'php_ffprobe' => $this->unsupportedProbeResult(\FFMpeg\FFProbe::class),
                    'shell_ffprobe' => $this->shellSupportProbeResult(),
                ],
            ];
        }

        $probes = [
            'getid3' => $this->probeWithGetId3($path),
            'php_ffprobe' => $this->probeWithPhpFfprobe($path),
            'shell_ffprobe' => $this->probeWithShellFfprobe($path),
        ];

        $winningExtractor = null;
        $rawMetadata = [];
        foreach ($probes as $name => $probe) {
            if (($probe['success'] ?? false) && is_array($probe['metadata'] ?? null)) {
                $winningExtractor = $name;
                $rawMetadata = $probe['metadata'];
                break;
            }
        }

        $normalized = $this->normalizeMetadata($path, $rawMetadata);
        $hasMeaningfulMetadata = ($normalized['duration_seconds'] ?? 0) > 0
            || ($normalized['bitrate_original'] ?? 0) > 0
            || ($normalized['sample_rate'] ?? 0) > 0
            || ($normalized['file_size_bytes'] ?? 0) > 0
            || ! empty($normalized['file_format']);

        $failureReason = null;
        if ($winningExtractor === null) {
            $failureReason = 'all_extractors_failed';
        } elseif (! $hasMeaningfulMetadata) {
            $failureReason = 'no_metadata_extracted';
        }

        return [
            'source_path' => $path,
            'resolved_path' => $path,
            'disk' => null,
            'temporary_file' => false,
            'exists' => true,
            'metadata' => $normalized,
            'extracted_by' => $winningExtractor,
            'failure_reason' => $failureReason,
            'extractors' => $probes,
        ];
    }

    private function materializeStoragePath(string $path, ?string $preferredDisk = null): array
    {
        $candidateDisks = array_values(array_unique(array_filter([
            $preferredDisk,
            StorageHelper::mediaDisk(),
            'public',
            'local',
        ])));

        foreach ($candidateDisks as $diskName) {
            try {
                $disk = Storage::disk($diskName);

                if (! $disk->exists($path)) {
                    continue;
                }

                try {
                    $absolutePath = $disk->path($path);
                    if (is_file($absolutePath)) {
                        return [$absolutePath, null, $diskName];
                    }
                } catch (\Throwable) {
                    // Remote disks do not expose a local filesystem path.
                }

                $stream = $disk->readStream($path);
                if (! is_resource($stream)) {
                    continue;
                }

                $extension = pathinfo($path, PATHINFO_EXTENSION);
                $temporaryPath = tempnam(sys_get_temp_dir(), 'song-meta-');
                if ($temporaryPath === false) {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    continue;
                }

                $targetPath = $extension !== ''
                    ? $temporaryPath.'.'.$extension
                    : $temporaryPath;

                if ($targetPath !== $temporaryPath && ! @rename($temporaryPath, $targetPath)) {
                    @unlink($temporaryPath);
                    fclose($stream);

                    continue;
                }

                $targetStream = fopen($targetPath, 'wb');
                if (! is_resource($targetStream)) {
                    fclose($stream);
                    @unlink($targetPath);

                    continue;
                }

                stream_copy_to_stream($stream, $targetStream);
                fclose($targetStream);
                fclose($stream);

                return [$targetPath, $targetPath, $diskName];
            } catch (\Throwable $e) {
                Log::debug('Audio metadata lookup skipped unavailable storage disk', [
                    'disk' => $diskName,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [null, null, null];
    }

    private function extractWithGetId3(string $path): ?array
    {
        return $this->probeWithGetId3($path)['metadata'] ?? null;
    }

    private function extractWithPhpFfprobe(string $path): ?array
    {
        return $this->probeWithPhpFfprobe($path)['metadata'] ?? null;
    }

    private function extractWithShellFfprobe(string $path): ?array
    {
        return $this->probeWithShellFfprobe($path)['metadata'] ?? null;
    }

    private function probeWithGetId3(string $path): array
    {
        if (! class_exists(\getID3::class)) {
            return $this->unsupportedProbeResult(\getID3::class);
        }

        try {
            $analyzer = new \getID3;
            $fileInfo = $analyzer->analyze($path);

            return [
                'supported' => true,
                'success' => true,
                'metadata' => [
                    'duration_seconds' => $fileInfo['playtime_seconds'] ?? null,
                    'bitrate_original' => $fileInfo['bitrate'] ?? null,
                    'sample_rate' => $fileInfo['audio']['sample_rate'] ?? null,
                    'file_format' => $fileInfo['fileformat'] ?? null,
                ],
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('getID3 metadata extraction failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'supported' => true,
                'success' => false,
                'metadata' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function probeWithPhpFfprobe(string $path): array
    {
        if (! class_exists(\FFMpeg\FFProbe::class)) {
            return $this->unsupportedProbeResult(\FFMpeg\FFProbe::class);
        }

        try {
            $ffprobe = \FFMpeg\FFProbe::create();
            $format = $ffprobe->format($path);
            $streams = $ffprobe->streams($path)->audios();
            $audioStream = $streams->first();

            return [
                'supported' => true,
                'success' => true,
                'metadata' => [
                    'duration_seconds' => $format->get('duration') ?? $format->get('duration_seconds'),
                    'bitrate_original' => $format->get('bit_rate'),
                    'sample_rate' => $audioStream?->get('sample_rate'),
                    'file_format' => pathinfo($path, PATHINFO_EXTENSION),
                ],
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('FFProbe PHP metadata extraction failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'supported' => true,
                'success' => false,
                'metadata' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function probeWithShellFfprobe(string $path): array
    {
        if (! function_exists('shell_exec')) {
            return $this->shellSupportProbeResult();
        }

        try {
            $command = sprintf(
                'ffprobe -v quiet -print_format json -show_format -show_streams %s 2>&1',
                escapeshellarg($path)
            );
            $output = shell_exec($command);

            if (! is_string($output) || trim($output) === '') {
                return [
                    'supported' => true,
                    'success' => false,
                    'metadata' => null,
                    'error' => 'ffprobe returned no output',
                ];
            }

            $data = json_decode($output, true);
            if (! is_array($data)) {
                return [
                    'supported' => true,
                    'success' => false,
                    'metadata' => null,
                    'error' => 'ffprobe returned invalid JSON',
                ];
            }

            $audioStream = collect($data['streams'] ?? [])
                ->first(fn (array $stream) => ($stream['codec_type'] ?? null) === 'audio');

            return [
                'supported' => true,
                'success' => true,
                'metadata' => [
                    'duration_seconds' => $data['format']['duration'] ?? null,
                    'bitrate_original' => $data['format']['bit_rate'] ?? null,
                    'sample_rate' => $audioStream['sample_rate'] ?? null,
                    'file_format' => pathinfo($path, PATHINFO_EXTENSION),
                ],
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Shell FFprobe metadata extraction failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'supported' => true,
                'success' => false,
                'metadata' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function unsupportedProbeResult(string $classOrCapability): array
    {
        return [
            'supported' => false,
            'success' => false,
            'metadata' => null,
            'error' => "{$classOrCapability} unavailable",
        ];
    }

    private function shellSupportProbeResult(): array
    {
        return [
            'supported' => function_exists('shell_exec'),
            'success' => false,
            'metadata' => null,
            'error' => function_exists('shell_exec') ? null : 'shell_exec unavailable',
        ];
    }

    private function normalizeMetadata(string $path, array $metadata): array
    {
        $durationSeconds = max(0, (int) round((float) ($metadata['duration_seconds'] ?? $metadata['duration'] ?? 0)));
        $bitrate = (int) round((float) ($metadata['bitrate_original'] ?? $metadata['bitrate'] ?? 0));
        $sampleRate = (int) round((float) ($metadata['sample_rate'] ?? 0));
        $fileSize = @filesize($path);
        $fileFormat = $metadata['file_format'] ?? pathinfo($path, PATHINFO_EXTENSION);

        return [
            'duration_seconds' => $durationSeconds,
            'duration_formatted' => $this->formatDuration($durationSeconds),
            'bitrate_original' => $bitrate > 0 ? $bitrate : null,
            'sample_rate' => $sampleRate > 0 ? $sampleRate : null,
            'file_size_bytes' => $fileSize !== false ? (int) $fileSize : null,
            'file_format' => is_string($fileFormat) && $fileFormat !== '' ? strtolower($fileFormat) : null,
        ];
    }

    private function emptyMetadata(): array
    {
        return [
            'duration_seconds' => 0,
            'duration_formatted' => '0:00',
            'bitrate_original' => null,
            'sample_rate' => null,
            'file_size_bytes' => null,
            'file_format' => null,
        ];
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0:00';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $remainingSeconds);
        }

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }
}
