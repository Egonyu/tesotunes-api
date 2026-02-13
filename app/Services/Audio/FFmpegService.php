<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class FFmpegService
{
    protected string $ffmpegBinary;
    protected string $ffprobeBinary;
    protected int $timeout;
    protected int $threads;

    public function __construct()
    {
        $this->ffmpegBinary = config('ffmpeg.binaries.ffmpeg', 'ffmpeg');
        $this->ffprobeBinary = config('ffmpeg.binaries.ffprobe', 'ffprobe');
        $this->timeout = config('ffmpeg.timeout', 3600);
        $this->threads = config('ffmpeg.threads', 4);
    }

    /**
     * Transcode audio to specified bitrate
     *
     * @param string $inputPath Path to input file
     * @param string $outputPath Path to output file
     * @param string $bitrate Bitrate (e.g., '320k', '128k')
     * @param int $sampleRate Sample rate in Hz (default: 44100)
     * @return bool Success status
     */
    public function transcode(string $inputPath, string $outputPath, string $bitrate, int $sampleRate = 44100): bool
    {
        try {
            $command = sprintf(
                '%s -i %s -b:a %s -ar %d -ac 2 -threads %d -y %s',
                $this->ffmpegBinary,
                escapeshellarg($inputPath),
                escapeshellarg($bitrate),
                $sampleRate,
                $this->threads,
                escapeshellarg($outputPath)
            );

            if (config('ffmpeg.error_handling.log_commands', true)) {
                Log::info('FFmpeg command', ['command' => $command]);
            }

            $result = Process::timeout($this->timeout)->run($command);

            if ($result->failed()) {
                Log::error('FFmpeg transcoding failed', [
                    'input' => $inputPath,
                    'output' => $outputPath,
                    'bitrate' => $bitrate,
                    'error' => $result->errorOutput()
                ]);
                return false;
            }

            Log::info('Audio transcoded successfully', [
                'input' => $inputPath,
                'output' => $outputPath,
                'bitrate' => $bitrate
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('FFmpeg transcoding exception', [
                'error' => $e->getMessage(),
                'input' => $inputPath,
                'output' => $outputPath
            ]);
            return false;
        }
    }

    /**
     * Generate 30-second preview clip
     *
     * @param string $inputPath Path to input file
     * @param string $outputPath Path to output file
     * @param int $startTime Start time in seconds (default: 0)
     * @param int $duration Duration in seconds (default: 30)
     * @return bool Success status
     */
    public function generatePreview(string $inputPath, string $outputPath, int $startTime = 0, int $duration = 30): bool
    {
        try {
            $previewConfig = config('ffmpeg.preview', []);
            $duration = $previewConfig['duration'] ?? $duration;
            $bitrate = config('ffmpeg.quality_presets.preview.bitrate', '96k');

            $command = sprintf(
                '%s -i %s -ss %d -t %d -b:a %s -threads %d -y %s',
                $this->ffmpegBinary,
                escapeshellarg($inputPath),
                $startTime,
                $duration,
                $bitrate,
                $this->threads,
                escapeshellarg($outputPath)
            );

            if (config('ffmpeg.error_handling.log_commands', true)) {
                Log::info('FFmpeg preview command', ['command' => $command]);
            }

            $result = Process::timeout($this->timeout)->run($command);

            if ($result->failed()) {
                Log::error('FFmpeg preview generation failed', [
                    'input' => $inputPath,
                    'output' => $outputPath,
                    'error' => $result->errorOutput()
                ]);
                return false;
            }

            Log::info('Preview generated successfully', [
                'input' => $inputPath,
                'output' => $outputPath,
                'duration' => $duration
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('FFmpeg preview generation exception', [
                'error' => $e->getMessage(),
                'input' => $inputPath,
                'output' => $outputPath
            ]);
            return false;
        }
    }

    /**
     * Generate waveform image
     *
     * @param string $inputPath Path to input file
     * @param string $outputPath Path to output PNG file
     * @param string $size Image size (e.g., '1920x200')
     * @param string $color Waveform color (default: 'blue')
     * @return bool Success status
     */
    public function generateWaveform(string $inputPath, string $outputPath, string $size = '1920x200', string $color = 'blue'): bool
    {
        try {
            $waveformConfig = config('ffmpeg.waveform', []);
            $width = $waveformConfig['width'] ?? 1920;
            $height = $waveformConfig['height'] ?? 200;
            $color = $waveformConfig['color'] ?? $color;
            $size = "{$width}x{$height}";

            $command = sprintf(
                '%s -i %s -filter_complex "showwavespic=s=%s:colors=%s" -y %s',
                $this->ffmpegBinary,
                escapeshellarg($inputPath),
                escapeshellarg($size),
                escapeshellarg($color),
                escapeshellarg($outputPath)
            );

            if (config('ffmpeg.error_handling.log_commands', true)) {
                Log::info('FFmpeg waveform command', ['command' => $command]);
            }

            $result = Process::timeout($this->timeout)->run($command);

            if ($result->failed()) {
                Log::error('FFmpeg waveform generation failed', [
                    'input' => $inputPath,
                    'output' => $outputPath,
                    'error' => $result->errorOutput()
                ]);
                return false;
            }

            Log::info('Waveform generated successfully', [
                'input' => $inputPath,
                'output' => $outputPath,
                'size' => $size
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('FFmpeg waveform generation exception', [
                'error' => $e->getMessage(),
                'input' => $inputPath,
                'output' => $outputPath
            ]);
            return false;
        }
    }

    /**
     * Extract audio metadata using ffprobe
     *
     * @param string $filePath Path to audio file
     * @return array|null Audio metadata or null on failure
     */
    public function extractMetadata(string $filePath): ?array
    {
        try {
            $command = sprintf(
                '%s -v quiet -print_format json -show_format -show_streams %s',
                $this->ffprobeBinary,
                escapeshellarg($filePath)
            );

            if (config('ffmpeg.error_handling.log_commands', true)) {
                Log::info('FFprobe command', ['command' => $command]);
            }

            $result = Process::timeout(60)->run($command);

            if ($result->failed()) {
                Log::error('FFprobe metadata extraction failed', [
                    'file' => $filePath,
                    'error' => $result->errorOutput()
                ]);
                return null;
            }

            $data = json_decode($result->output(), true);

            if (!$data) {
                return null;
            }

            // Extract relevant audio stream info
            $audioStream = collect($data['streams'] ?? [])
                ->first(fn($stream) => ($stream['codec_type'] ?? '') === 'audio');

            return [
                'duration' => (float) ($data['format']['duration'] ?? 0),
                'bitrate' => (int) ($data['format']['bit_rate'] ?? 0),
                'sample_rate' => (int) ($audioStream['sample_rate'] ?? 0),
                'channels' => (int) ($audioStream['channels'] ?? 0),
                'codec' => $audioStream['codec_name'] ?? 'unknown',
                'format' => $data['format']['format_name'] ?? 'unknown',
                'size' => (int) ($data['format']['size'] ?? 0),
            ];

        } catch (\Exception $e) {
            Log::error('FFprobe metadata extraction exception', [
                'error' => $e->getMessage(),
                'file' => $filePath
            ]);
            return null;
        }
    }

    /**
     * Validate FFmpeg is installed and accessible
     *
     * @return bool True if FFmpeg is available
     */
    public function isAvailable(): bool
    {
        try {
            $result = Process::run("{$this->ffmpegBinary} -version");
            return $result->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get FFmpeg version information
     *
     * @return string|null Version string or null if not available
     */
    public function getVersion(): ?string
    {
        try {
            $result = Process::run("{$this->ffmpegBinary} -version");
            if ($result->successful()) {
                $lines = explode("\n", $result->output());
                return $lines[0] ?? null;
            }
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Transcode using quality preset
     *
     * @param string $inputPath
     * @param string $outputPath
     * @param string $preset 'high', 'medium', 'low', or 'preview'
     * @return bool
     */
    public function transcodeWithPreset(string $inputPath, string $outputPath, string $preset = 'medium'): bool
    {
        $config = config("ffmpeg.quality_presets.{$preset}");
        
        if (!$config) {
            Log::error("Invalid FFmpeg preset: {$preset}");
            return false;
        }

        return $this->transcode(
            $inputPath,
            $outputPath,
            $config['bitrate'],
            $config['sample_rate']
        );
    }

    /**
     * Get supported audio formats
     *
     * @return array
     */
    public function getSupportedFormats(): array
    {
        return config('ffmpeg.supported_formats', []);
    }

    /**
     * Create necessary directories for FFmpeg operations
     *
     * @return void
     */
    public function ensureDirectories(): void
    {
        $paths = config('ffmpeg.paths', []);
        
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                mkdir($path, 0755, true);
            }
        }
    }
}
