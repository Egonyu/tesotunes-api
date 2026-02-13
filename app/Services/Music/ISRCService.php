<?php

namespace App\Services\Music;

use App\Models\Song;
use App\Models\ISRCCode;
use Exception;

/**
 * ISRC (International Standard Recording Code) Service
 * 
 * Generates and validates ISRC codes for Uganda
 * Format: UG-XXX-YY-NNNNN
 * - UG: Uganda country code
 * - XXX: Registrant code (3 characters)
 * - YY: Year (last 2 digits)
 * - NNNNN: Designation code (5 digits, auto-increment)
 */
class ISRCService
{
    protected string $countryCode;
    protected string $registrantCode;

    public function __construct()
    {
        $this->countryCode = config('music.isrc.country_code', 'UG');
        $this->registrantCode = config('music.isrc.registrant_prefix', 'MUS');
    }

    /**
     * Generate ISRC code for a song
     * 
     * @param Song $song
     * @return string ISRC code (e.g., UG-MUS-25-00001)
     * @throws Exception
     */
    public function generate(Song $song): string
    {
        // Validate registrant code is configured
        if (strlen($this->registrantCode) !== 3) {
            throw new Exception('ISRC registrant code must be exactly 3 characters');
        }

        $year = now()->format('y');
        $designation = $this->getNextDesignationCode($year);

        $isrcCode = "{$this->countryCode}-{$this->registrantCode}-{$year}-{$designation}";

        // Validate format
        if (!$this->validateFormat($isrcCode)) {
            throw new Exception('Generated ISRC code failed validation');
        }

        // Check for duplicates (extremely rare but possible)
        if ($this->exists($isrcCode)) {
            \Log::warning('ISRC collision detected, regenerating', ['isrc' => $isrcCode]);
            return $this->generate($song); // Regenerate
        }

        // Store in ISRC registry
        $this->register($isrcCode, $song);

        return $isrcCode;
    }

    /**
     * Get next designation code for the year
     * Resets annually: 00001, 00002, ..., 99999
     */
    protected function getNextDesignationCode(string $year): string
    {
        $lastISRC = ISRCCode::where('year', $year)
            ->where('registrant_code', $this->registrantCode)
            ->orderBy('designation_number', 'desc')
            ->first();

        $nextNumber = $lastISRC ? $lastISRC->designation_number + 1 : 1;

        if ($nextNumber > 99999) {
            throw new Exception('ISRC designation limit reached for year ' . $year);
        }

        return str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Validate ISRC format
     * Pattern: XX-XXX-YY-NNNNN
     */
    public function validateFormat(string $isrcCode): bool
    {
        $pattern = '/^[A-Z]{2}-[A-Z0-9]{3}-\d{2}-\d{5}$/';
        return preg_match($pattern, $isrcCode) === 1;
    }

    /**
     * Check if ISRC code already exists
     */
    public function exists(string $isrcCode): bool
    {
        return ISRCCode::where('code', $isrcCode)->exists() ||
               Song::where('isrc_code', $isrcCode)->exists();
    }

    /**
     * Register ISRC code in database
     */
    protected function register(string $isrcCode, Song $song): void
    {
        // Parse ISRC components
        [$country, $registrant, $year, $designation] = explode('-', $isrcCode);

        ISRCCode::create([
            'code' => $isrcCode,
            'country_code' => $country,
            'registrant_code' => $registrant,
            'year' => $year,
            'designation_number' => (int) $designation,
            'song_id' => $song->id,
            'artist_id' => $song->artist_id,
            'issued_at' => now(),
            'status' => 'active',
        ]);

        \Log::info('ISRC registered', [
            'isrc' => $isrcCode,
            'song_id' => $song->id,
        ]);
    }

    /**
     * Verify ISRC against IFPI database (placeholder)
     * In production, this would call IFPI API
     */
    public function verifyWithIFPI(string $isrcCode): bool
    {
        // TODO: Implement IFPI API integration
        // For now, just validate format
        return $this->validateFormat($isrcCode);
    }

    /**
     * Bulk generate ISRC codes for multiple songs
     */
    public function bulkGenerate(array $songs): array
    {
        $isrcCodes = [];

        foreach ($songs as $song) {
            try {
                $isrcCodes[$song->id] = $this->generate($song);
            } catch (Exception $e) {
                \Log::error('Failed to generate ISRC', [
                    'song_id' => $song->id,
                    'error' => $e->getMessage(),
                ]);
                $isrcCodes[$song->id] = null;
            }
        }

        return $isrcCodes;
    }
}
