<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class ISRCCode extends Model
{
    use HasFactory;

    protected $table = 'isrc_codes';

    protected $fillable = [
        'isrc_code',
        'formatted_isrc',
        'song_id',
        'artist_id',
        'album_id',
        'country_code',
        'registrant_code',
        'year_code',
        'designation_code',
        'registrant_name',
        'recording_date',
        'recording_location',
        'recording_details',
        'master_ownership_percentage',
        'publishing_ownership_percentage',
        'rights_holders',
        'publishing_splits',
        'status',
        'registered_at',
        'generated_at',
        'registration_authority',
        'registration_reference',
        'international_registration',
        'international_territories',
        'international_registered_at',
        'work_title',
        'alternative_titles',
        'version_info',
        'duration_seconds',
        'genres',
        'primary_language',
        'featured_artists',
        'copyright_owner',
        'copyright_year',
        'phonogram_producer',
        'phonogram_year',
        'cleared_for_distribution',
        'distribution_cleared_at',
        'distribution_restrictions',
        'territorial_restrictions',
    ];

    protected $casts = [
        'recording_date' => 'date',
        'recording_details' => 'array',
        'master_ownership_percentage' => 'decimal:2',
        'publishing_ownership_percentage' => 'decimal:2',
        'rights_holders' => 'array',
        'publishing_splits' => 'array',
        'registered_at' => 'datetime',
        'international_registration' => 'boolean',
        'international_territories' => 'array',
        'international_registered_at' => 'datetime',
        'alternative_titles' => 'array',
        'duration_seconds' => 'integer',
        'genres' => 'array',
        'featured_artists' => 'array',
        'copyright_year' => 'integer',
        'phonogram_year' => 'integer',
        'cleared_for_distribution' => 'boolean',
        'distribution_cleared_at' => 'datetime',
        'distribution_restrictions' => 'array',
        'territorial_restrictions' => 'array',
    ];

    // Relationships
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function publishingRights(): HasMany
    {
        return $this->hasMany(PublishingRights::class, 'song_id', 'song_id');
    }

    public function royaltySplits(): HasMany
    {
        return $this->hasMany(RoyaltySplit::class, 'song_id', 'song_id');
    }

    // Scopes
    public function scopeRegistered($query)
    {
        return $query->where('status', 'registered');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeClearedForDistribution($query)
    {
        return $query->where('cleared_for_distribution', true);
    }

    public function scopeUgandanCodes($query)
    {
        return $query->where('country_code', 'UG');
    }

    public function scopeByYear($query, int $year)
    {
        $yearCode = substr($year, 2, 2);
        return $query->where('year_code', $yearCode);
    }

    public function scopeByArtist($query, int $artistId)
    {
        return $query->where('artist_id', $artistId);
    }

    public function scopeInternational($query)
    {
        return $query->where('international_registration', true);
    }

    // Mutators and Accessors
    public function getCodeAttribute(): ?string
    {
        if (!$this->country_code || !$this->registrant_code || !$this->year_code || !$this->designation_code) {
            return null;
        }
        return $this->country_code . '-' . $this->registrant_code . '-' . $this->year_code . '-' . $this->designation_code;
    }

    public function getFormattedIsrcAttribute(): string
    {
        return $this->country_code . '-' . $this->registrant_code . '-' . $this->year_code . '-' . $this->designation_code;
    }

    public function getAgeInYearsAttribute(): int
    {
        return $this->recording_date->diffInYears(now());
    }

    public function getRegistrationStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'pending' => '⏳ Pending',
            'registered' => '✅ Registered',
            'disputed' => '⚠️ Disputed',
            'cancelled' => '❌ Cancelled',
            default => '❓ Unknown'
        };
    }

    public function getDistributionStatusBadgeAttribute(): string
    {
        if ($this->cleared_for_distribution) {
            return '✅ Cleared';
        }
        return '⏳ Pending Clearance';
    }

    // Helper Methods
    public function isRegistered(): bool
    {
        return $this->status === 'registered';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isDisputed(): bool
    {
        return $this->status === 'disputed';
    }

    public function isClearedForDistribution(): bool
    {
        return $this->cleared_for_distribution && $this->isRegistered();
    }

    public function hasInternationalRegistration(): bool
    {
        return $this->international_registration;
    }

    public function getTotalOwnershipPercentage(): float
    {
        return $this->master_ownership_percentage + $this->publishing_ownership_percentage;
    }

    public function canBeDistributedTo(string $territory): bool
    {
        if (!$this->isClearedForDistribution()) {
            return false;
        }

        // Check territorial restrictions
        if ($this->territorial_restrictions && in_array($territory, $this->territorial_restrictions)) {
            return false;
        }

        // Check if international registration is required
        if ($territory !== 'Uganda' && !$this->hasInternationalRegistration()) {
            return false;
        }

        return true;
    }

    public function markAsRegistered(string $registrationReference = null): void
    {
        $this->update([
            'status' => 'registered',
            'registered_at' => now(),
            'registration_reference' => $registrationReference,
        ]);
    }

    public function clearForDistribution(array $restrictions = []): void
    {
        $this->update([
            'cleared_for_distribution' => true,
            'distribution_cleared_at' => now(),
            'distribution_restrictions' => $restrictions,
        ]);
    }

    public function addTerritorialRestriction(string $territory): void
    {
        $restrictions = $this->territorial_restrictions ?? [];
        if (!in_array($territory, $restrictions)) {
            $restrictions[] = $territory;
            $this->update(['territorial_restrictions' => $restrictions]);
        }
    }

    public function removeTerritorialRestriction(string $territory): void
    {
        $restrictions = $this->territorial_restrictions ?? [];
        $restrictions = array_diff($restrictions, [$territory]);
        $this->update(['territorial_restrictions' => array_values($restrictions)]);
    }

    public function enableInternationalRegistration(array $territories = []): void
    {
        $this->update([
            'international_registration' => true,
            'international_territories' => $territories ?: ['Global'],
            'international_registered_at' => now(),
        ]);
    }

    // Static methods for ISRC generation
    public static function generateForSong(Song $song): self
    {
        $artist = $song->artist;

        // Generate ISRC components using official UG-A65 prefix
        $countryCode = config('music.isrc.country_code', 'UG'); // Uganda
        $registrantCode = config('music.isrc.registrant_code', 'A65'); // Official prefix
        $yearCode = substr(now()->year, 2, 2); // Current year
        $designationCode = self::generateDesignationCode($yearCode);

        // Format: UGA6525NNNNN (12 chars) or UG-A65-25-NNNNN (with dashes)
        $isrcCode = $countryCode . $registrantCode . $yearCode . $designationCode;
        $formattedIsrc = $countryCode . '-' . $registrantCode . '-' . $yearCode . '-' . $designationCode;

        return self::create([
            'isrc_code' => $isrcCode,
            'formatted_isrc' => $formattedIsrc,
            'song_id' => $song->id,
            'artist_id' => $artist->id,
            'album_id' => $song->album_id,
            'country_code' => $countryCode,
            'registrant_code' => $registrantCode,
            'year_code' => $yearCode,
            'designation_code' => $designationCode,
            'registrant_name' => config('music.isrc.registrant_name', 'TesoTunes'),
            'recording_date' => $song->release_date ?? now(),
            'work_title' => $song->title,
            'duration_seconds' => $song->duration_seconds,
            'primary_language' => $song->primary_language ?? 'English',
            'copyright_owner' => $artist->stage_name,
            'copyright_year' => $song->created_at?->year ?? now()->year,
            'phonogram_producer' => $artist->stage_name,
            'phonogram_year' => $song->created_at?->year ?? now()->year,
            'status' => 'pending',
            'generated_at' => now(),
        ]);
    }

    /**
     * Generate the next available designation code for a given year
     * Ensures unique 5-digit sequential codes per year
     */
    private static function generateDesignationCode(string $yearCode): string
    {
        // Get the highest designation code for this year
        $maxCode = self::where('year_code', $yearCode)
            ->where('registrant_code', config('music.isrc.registrant_code', 'A65'))
            ->max('designation_code');

        if ($maxCode) {
            $nextNumber = intval($maxCode) + 1;
        } else {
            $nextNumber = 1;
        }

        return str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a human-readable formatted ISRC
     * Example: UG-A65-26-00001
     */
    public static function formatForDisplay(string $isrc): string
    {
        $clean = str_replace('-', '', strtoupper($isrc));
        if (strlen($clean) !== 12) {
            return $isrc;
        }
        
        return substr($clean, 0, 2) . '-' . 
               substr($clean, 2, 3) . '-' . 
               substr($clean, 5, 2) . '-' . 
               substr($clean, 7, 5);
    }

    /**
     * Get ISRC statistics for reporting
     */
    public static function getStatistics(): array
    {
        $currentYear = substr(now()->year, 2, 2);
        
        return [
            'total_codes' => self::count(),
            'codes_this_year' => self::where('year_code', $currentYear)->count(),
            'registered_codes' => self::where('status', 'registered')->count(),
            'pending_codes' => self::where('status', 'pending')->count(),
            'cleared_for_distribution' => self::where('cleared_for_distribution', true)->count(),
            'next_designation_code' => self::generateDesignationCode($currentYear),
            'prefix' => config('music.isrc.country_code') . '-' . config('music.isrc.registrant_code'),
        ];
    }

    public static function validateISRCFormat(string $isrc): bool
    {
        // ISRC format: CCXXXYYNNNNN (12 characters total, no dashes)
        return preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{2}[0-9]{5}$/', $isrc);
    }

    public static function parseISRC(string $isrc): array
    {
        $clean = str_replace('-', '', strtoupper($isrc));

        if (!self::validateISRCFormat($clean)) {
            throw new \InvalidArgumentException('Invalid ISRC format');
        }

        return [
            'country_code' => substr($clean, 0, 2),
            'registrant_code' => substr($clean, 2, 3),
            'year_code' => substr($clean, 5, 2),
            'designation_code' => substr($clean, 7, 5),
            'full_year' => '20' . substr($clean, 5, 2),
        ];
    }

    public static function generateBatch(array $songIds): array
    {
        $codes = [];
        foreach ($songIds as $songId) {
            $song = Song::find($songId);
            if ($song) {
                $codes[] = self::generateForSong($song);
            }
        }
        return $codes;
    }
}