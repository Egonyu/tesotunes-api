<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ISRCCode extends Model
{
    use HasFactory;

    protected $table = 'isrc_codes';

    protected $fillable = [
        'song_id',
        'artist_id',
        'code',
        'country_code',
        'registrant_code',
        'year_code',
        'designation_code',
        'status',
        'registration_authority',
        'registration_reference',
        'cleared_for_distribution',
        'distribution_cleared_at',
        'registered_at',
        'is_verified',
        'verified_at',
    ];

    protected $casts = [
        'cleared_for_distribution' => 'boolean',
        'is_verified' => 'boolean',
        'registered_at' => 'datetime',
        'distribution_cleared_at' => 'datetime',
        'verified_at' => 'datetime',
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
        return $query->where('status', 'active');
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
        return $query->where('year_code', substr($year, 2, 2));
    }

    public function scopeByArtist($query, int $artistId)
    {
        return $query->where('artist_id', $artistId);
    }

    // Accessors
    public function getFormattedIsrcAttribute(): ?string
    {
        if (! $this->country_code || ! $this->registrant_code || ! $this->year_code || ! $this->designation_code) {
            return $this->code;
        }

        return $this->country_code.'-'.$this->registrant_code.'-'.$this->year_code.'-'.$this->designation_code;
    }

    public function getRegistrationStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'active' => 'Registered',
            'pending' => 'Pending',
            'disputed' => 'Disputed',
            'inactive' => 'Inactive',
            default => 'Unknown'
        };
    }

    public function getDistributionStatusBadgeAttribute(): string
    {
        return $this->cleared_for_distribution ? 'Cleared' : 'Pending Clearance';
    }

    // Helper Methods
    public function isRegistered(): bool
    {
        return $this->status === 'active';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isClearedForDistribution(): bool
    {
        return $this->cleared_for_distribution && $this->isRegistered();
    }

    public function markAsRegistered(?string $registrationReference = null): void
    {
        $this->update([
            'status' => 'active',
            'registered_at' => now(),
            'registration_reference' => $registrationReference,
        ]);
    }

    public function clearForDistribution(): void
    {
        $this->update([
            'cleared_for_distribution' => true,
            'distribution_cleared_at' => now(),
        ]);
    }

    // Static methods for ISRC generation
    public static function generateForSong(Song $song): self
    {
        $artist = $song->artist;

        $countryCode = config('music.isrc.country_code', 'UG');
        $registrantCode = config('music.isrc.registrant_code', 'A65');
        $yearCode = substr(now()->year, 2, 2);
        $designationCode = self::generateDesignationCode($yearCode);

        $code = $countryCode.$registrantCode.$yearCode.$designationCode;

        return self::create([
            'code' => $code,
            'song_id' => $song->id,
            'artist_id' => $artist->id,
            'country_code' => $countryCode,
            'registrant_code' => $registrantCode,
            'year_code' => $yearCode,
            'designation_code' => $designationCode,
            'status' => 'pending',
        ]);
    }

    private static function generateDesignationCode(string $yearCode): string
    {
        $maxCode = self::where('year_code', $yearCode)
            ->where('registrant_code', config('music.isrc.registrant_code', 'A65'))
            ->max('designation_code');

        $nextNumber = $maxCode ? intval($maxCode) + 1 : 1;

        return str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    public static function formatForDisplay(string $isrc): string
    {
        $clean = str_replace('-', '', strtoupper($isrc));
        if (strlen($clean) < 12) {
            return $isrc;
        }

        return substr($clean, 0, 2).'-'.
               substr($clean, 2, 3).'-'.
               substr($clean, 5, 2).'-'.
               substr($clean, 7, 5);
    }

    public static function validateISRCFormat(string $isrc): bool
    {
        return preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{2}[0-9]{5}$/', $isrc) === 1;
    }

    public static function getStatistics(): array
    {
        $currentYear = substr(now()->year, 2, 2);

        return [
            'total_codes' => self::count(),
            'codes_this_year' => self::where('year_code', $currentYear)->count(),
            'registered_codes' => self::where('status', 'active')->count(),
            'pending_codes' => self::where('status', 'pending')->count(),
            'cleared_for_distribution' => self::where('cleared_for_distribution', true)->count(),
            'next_designation_code' => self::generateDesignationCode($currentYear),
            'prefix' => config('music.isrc.country_code').'-'.config('music.isrc.registrant_code'),
        ];
    }
}
