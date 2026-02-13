<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Audio Watermarking Service
 * 
 * Adds audio watermarks (e.g., "tesotunes.com") to uploaded songs
 * at the beginning, middle, and end of the track.
 */
class AudioWatermarkService
{
    /**
     * Watermark audio file path (relative to storage/app)
     */
    protected string $watermarkPath = 'watermarks/tesotunes_watermark.mp3';

    /**
     * Watermark volume (0.0 to 1.0)
     */
    protected float $watermarkVolume = 0.3;

    /**
     * Fade in/out duration in seconds
     */
    protected float $fadeDuration = 0.5;

    /**
     * Add watermarks to an audio file
     * 
     * @param string $inputPath Path to input audio file
     * @param string $outputPath Path to save watermarked output
     * @param array $options Configuration options
     * @return bool Success status
     */
    public function watermarkAudio(string $inputPath, string $outputPath, array $options = []): bool
    {
        try {
            // Check if FFmpeg is available
            if (!$this->checkFFmpegAvailable()) {
                Log::warning('FFmpeg not available, skipping watermark');
                // Copy original file instead
                return copy($inputPath, $outputPath);
            }

            // Check if watermark file exists
            if (!$this->ensureWatermarkExists()) {
                Log::warning('Watermark file not found, skipping watermark');
                return copy($inputPath, $outputPath);
            }

            // Extract options
            $positions = $options['positions'] ?? ['start', 'middle', 'end'];
            $volume = $options['volume'] ?? $this->watermarkVolume;

            // Get audio duration
            $duration = $this->getAudioDuration($inputPath);
            
            if (!$duration || $duration < 30) {
                Log::info('Audio too short for watermarking, copying original');
                return copy($inputPath, $outputPath);
            }

            // Build FFmpeg filter for watermarking
            $filter = $this->buildWatermarkFilter($duration, $positions, $volume);

            // Execute FFmpeg command
            return $this->executeWatermarking($inputPath, $outputPath, $filter);

        } catch (\Exception $e) {
            Log::error('Audio watermarking failed: ' . $e->getMessage(), [
                'input' => $inputPath,
                'output' => $outputPath,
                'error' => $e->getMessage()
            ]);
            
            // Fallback: copy original file
            return copy($inputPath, $outputPath);
        }
    }

    /**
     * Check if FFmpeg is available
     */
    protected function checkFFmpegAvailable(): bool
    {
        $process = new Process(['ffmpeg', '-version']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Ensure watermark audio file exists
     */
    protected function ensureWatermarkExists(): bool
    {
        // Check if watermark exists
        if (Storage::exists($this->watermarkPath)) {
            return true;
        }

        // Try to create watermark directory
        $watermarkDir = dirname(storage_path('app/' . $this->watermarkPath));
        if (!is_dir($watermarkDir)) {
            mkdir($watermarkDir, 0755, true);
        }

        Log::warning('Watermark file does not exist: ' . $this->watermarkPath);
        Log::info('Please create a watermark audio file at: ' . storage_path('app/' . $this->watermarkPath));
        
        return false;
    }

    /**
     * Get audio duration in seconds
     */
    protected function getAudioDuration(string $filePath): ?float
    {
        $command = [
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $filePath
        ];

        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return (float) trim($process->getOutput());
    }

    /**
     * Build FFmpeg filter for watermarking
     */
    protected function buildWatermarkFilter(float $duration, array $positions, float $volume): string
    {
        $watermarkFile = storage_path('app/' . $this->watermarkPath);
        $filters = [];
        $inputs = "[0:a]";
        $currentInput = 0;

        // Calculate positions
        $times = [];
        if (in_array('start', $positions)) {
            $times[] = 3; // 3 seconds from start
        }
        if (in_array('middle', $positions)) {
            $times[] = $duration / 2; // Middle of track
        }
        if (in_array('end', $positions)) {
            $times[] = $duration - 8; // 8 seconds before end
        }

        // Build overlay filters for each position
        foreach ($times as $index => $time) {
            $inputLabel = $index === 0 ? "[0:a]" : "[a{$index}]";
            $outputLabel = "[a" . ($index + 1) . "]";
            
            $filters[] = "{$inputLabel}[{$index}:a]adelay={$time}s:all=1,volume={$volume}[w{$index}]";
            $filters[] = "{$inputLabel}[w{$index}]amix=inputs=2:duration=first{$outputLabel}";
        }

        return implode(';', $filters);
    }

    /**
     * Execute watermarking with FFmpeg
     */
    protected function executeWatermarking(string $inputPath, string $outputPath, string $filter): bool
    {
        $watermarkFile = storage_path('app/' . $this->watermarkPath);

        // Simplified approach: Mix at specific positions
        $command = $this->buildSimplifiedCommand($inputPath, $outputPath, $watermarkFile);

        $process = new Process($command);
        $process->setTimeout(600); // 10 minutes max
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return file_exists($outputPath);
    }

    /**
     * Build simplified watermarking command
     * This version is more reliable and easier to maintain
     */
    protected function buildSimplifiedCommand(string $inputPath, string $outputPath, string $watermarkPath): array
    {
        // Get duration of input
        $duration = $this->getAudioDuration($inputPath);
        $midPoint = $duration / 2;
        $endPoint = max(0, $duration - 8);

        // Create temporary watermarked segments
        $tempDir = sys_get_temp_dir();
        $segmentStart = "{$tempDir}/segment_start_" . uniqid() . ".mp3";
        $segmentMiddle = "{$tempDir}/segment_middle_" . uniqid() . ".mp3";
        $segmentEnd = "{$tempDir}/segment_end_" . uniqid() . ".mp3";

        // Simple approach: Overlay watermark at three points using amix
        return [
            'ffmpeg',
            '-i', $inputPath,
            '-i', $watermarkPath,
            '-i', $watermarkPath,
            '-i', $watermarkPath,
            '-filter_complex',
            "[1:a]adelay=3000|3000[a1];
             [2:a]adelay=" . ($midPoint * 1000) . "|" . ($midPoint * 1000) . "[a2];
             [3:a]adelay=" . ($endPoint * 1000) . "|" . ($endPoint * 1000) . "[a3];
             [0:a][a1][a2][a3]amix=inputs=4:duration=first:dropout_transition=0,volume=4[aout]",
            '-map', '[aout]',
            '-b:a', '320k',
            '-ar', '44100',
            '-y', // Overwrite output file
            $outputPath
        ];
    }

    /**
     * Create a text-to-speech watermark audio file
     * This is a helper method to generate the watermark file if needed
     */
    public function createTextWatermark(string $text = 'tesotunes.com', string $outputPath = null): bool
    {
        if (!$outputPath) {
            $outputPath = storage_path('app/' . $this->watermarkPath);
        }

        // Ensure directory exists
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Use FFmpeg with silent audio and a beep tone as placeholder
        // In production, you would use a proper TTS service or pre-recorded audio
        $command = [
            'ffmpeg',
            '-f', 'lavfi',
            '-i', 'sine=frequency=1000:duration=2',
            '-af', 'volume=0.3',
            '-b:a', '128k',
            '-y',
            $outputPath
        ];

        $process = new Process($command);
        $process->run();

        return $process->isSuccessful() && file_exists($outputPath);
    }

    /**
     * Quick watermark method for job processing
     * 
     * @param string $songId Song ID
     * @param string $inputPath Original audio file
     * @return string|null Path to watermarked file or null on failure
     */
    public function watermarkSong(string $songId, string $inputPath): ?string
    {
        // Define output path
        $outputPath = storage_path("app/music/{$songId}/watermarked/" . basename($inputPath));
        
        // Ensure output directory exists
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Perform watermarking
        $success = $this->watermarkAudio($inputPath, $outputPath);

        return $success ? $outputPath : null;
    }

    /**
     * Watermark and replace original file
     * USE WITH CAUTION - This replaces the original file
     */
    public function watermarkInPlace(string $filePath): bool
    {
        $tempOutput = $filePath . '.watermarked.tmp';
        
        if ($this->watermarkAudio($filePath, $tempOutput)) {
            // Replace original with watermarked version
            return rename($tempOutput, $filePath);
        }

        // Cleanup temp file if exists
        if (file_exists($tempOutput)) {
            unlink($tempOutput);
        }

        return false;
    }
}
