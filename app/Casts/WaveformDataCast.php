<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Custom cast for audio waveform visualization data
 *
 * Used for: Song waveform display in player
 *
 * Validates:
 * - Must be array of integers
 * - Values between 0-100 (amplitude percentages)
 * - Typical length: 100-500 data points
 * - Maximum length: 1000 points (performance limit)
 * - Can be compressed for mobile efficiency
 *
 * Example structure:
 * [45, 67, 89, 23, 56, 78, ...] // 100-500 points
 *
 * Or with metadata:
 * {
 *   "data": [45, 67, 89, ...],
 *   "sample_rate": 44100,
 *   "duration": 180,
 *   "points_per_second": 2
 * }
 */
class WaveformDataCast implements CastsAttributes
{
    const MIN_VALUE = 0;
    const MAX_VALUE = 100;
    const MAX_POINTS = 1000;
    const TYPICAL_POINTS = 300; // Good balance of detail vs size
    const MIN_POINTS = 50;

    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        // Support both simple array and metadata format
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded; // Return full metadata structure
        }

        // Simple array format
        return ['data' => $decoded];
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException("Waveform data must be an array");
        }

        $validated = $this->validate($value);

        return json_encode($validated);
    }

    /**
     * Validate waveform data structure
     */
    protected function validate(array $waveform): array
    {
        // Handle metadata format
        if (isset($waveform['data'])) {
            $data = $waveform['data'];
            $metadata = array_diff_key($waveform, ['data' => null]);

            // Validate metadata fields
            $validated = ['data' => $this->validateDataPoints($data)];

            if (isset($metadata['sample_rate'])) {
                if (!is_int($metadata['sample_rate']) || $metadata['sample_rate'] <= 0) {
                    throw new InvalidArgumentException("Sample rate must be a positive integer");
                }
                $validated['sample_rate'] = $metadata['sample_rate'];
            }

            if (isset($metadata['duration'])) {
                if (!is_numeric($metadata['duration']) || $metadata['duration'] <= 0) {
                    throw new InvalidArgumentException("Duration must be a positive number");
                }
                $validated['duration'] = (float) $metadata['duration'];
            }

            if (isset($metadata['points_per_second'])) {
                if (!is_numeric($metadata['points_per_second']) || $metadata['points_per_second'] <= 0) {
                    throw new InvalidArgumentException("Points per second must be a positive number");
                }
                $validated['points_per_second'] = (float) $metadata['points_per_second'];
            }

            return $validated;
        }

        // Simple array format
        return ['data' => $this->validateDataPoints($waveform)];
    }

    /**
     * Validate individual data points
     */
    protected function validateDataPoints(array $points): array
    {
        $count = count($points);

        // Check point count
        if ($count < self::MIN_POINTS) {
            throw new InvalidArgumentException("Waveform must have at least " . self::MIN_POINTS . " data points. Got: {$count}");
        }

        if ($count > self::MAX_POINTS) {
            throw new InvalidArgumentException("Waveform exceeds maximum of " . self::MAX_POINTS . " data points. Got: {$count}");
        }

        // Validate each point
        $validated = [];
        foreach ($points as $index => $point) {
            if (!is_numeric($point)) {
                throw new InvalidArgumentException("Waveform point at index {$index} must be numeric");
            }

            $value = (int) $point;

            if ($value < self::MIN_VALUE || $value > self::MAX_VALUE) {
                throw new InvalidArgumentException("Waveform point at index {$index} must be between " . self::MIN_VALUE . " and " . self::MAX_VALUE . ". Got: {$value}");
            }

            $validated[] = $value;
        }

        return $validated;
    }

    /**
     * Generate waveform from audio file (utility method)
     * Note: This would integrate with FFmpeg or audio processing library
     */
    public static function generateFromAudio(string $audioPath, int $points = self::TYPICAL_POINTS): array
    {
        // Placeholder for actual FFmpeg integration
        // In production, this would:
        // 1. Read audio file
        // 2. Extract amplitude data
        // 3. Normalize to 0-100 range
        // 4. Downsample to desired point count

        // For now, return empty structure
        return [
            'data' => array_fill(0, $points, 50), // Placeholder flat line
            'sample_rate' => 44100,
            'points_per_second' => 2,
        ];
    }

    /**
     * Compress waveform for mobile (reduce point count)
     */
    public static function compressForMobile(array $waveform, int $targetPoints = 100): array
    {
        $data = $waveform['data'] ?? $waveform;

        if (!is_array($data)) {
            return $waveform;
        }

        $currentPoints = count($data);

        if ($currentPoints <= $targetPoints) {
            return $waveform;
        }

        // Downsample by averaging groups
        $step = $currentPoints / $targetPoints;
        $compressed = [];

        for ($i = 0; $i < $targetPoints; $i++) {
            $start = (int) ($i * $step);
            $end = (int) (($i + 1) * $step);
            $group = array_slice($data, $start, $end - $start);
            $compressed[] = (int) (array_sum($group) / count($group));
        }

        if (isset($waveform['data'])) {
            $waveform['data'] = $compressed;
            return $waveform;
        }

        return $compressed;
    }

    /**
     * Get peak amplitude value
     */
    public static function getPeakAmplitude(array $waveform): int
    {
        $data = $waveform['data'] ?? $waveform;
        return max($data);
    }

    /**
     * Get average amplitude
     */
    public static function getAverageAmplitude(array $waveform): float
    {
        $data = $waveform['data'] ?? $waveform;
        return array_sum($data) / count($data);
    }

    /**
     * Normalize waveform data (ensure peak is at 100)
     */
    public static function normalize(array $waveform): array
    {
        $data = $waveform['data'] ?? $waveform;
        $peak = max($data);

        if ($peak === 0) {
            return $waveform;
        }

        $scale = self::MAX_VALUE / $peak;
        $normalized = array_map(fn($value) => (int) ($value * $scale), $data);

        if (isset($waveform['data'])) {
            $waveform['data'] = $normalized;
            return $waveform;
        }

        return $normalized;
    }
}
