<?php

namespace App\Models;

use App\Models\Concerns\HasAudioUrls;
use App\Models\Concerns\HasDistributionStatus;
use App\Models\Concerns\HasIsrcManagement;
use App\Models\Traits\Featurable;
use App\Traits\HasComments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Song extends Model
{
    use Featurable, HasAudioUrls, HasComments, HasDistributionStatus, HasFactory, HasIsrcManagement, SoftDeletes;

    protected $fillable = [
        // Core identity
        'user_id',
        'artist_id',
        'album_id',
        'title',
        'slug',
        'description',
        'lyrics',

        // Audio files (transcoded versions) - use canonical DB column names
        'audio_file_original',
        'audio_file_320',
        'audio_file_128',
        'audio_file_preview',
        'artwork',
        'waveform_data',

        // File metadata - use canonical DB column names
        'duration_seconds',
        'file_format',
        'file_size_bytes',
        'bitrate_original',
        'sample_rate',
        'audio_quality_score',
        'file_hash',

        // Classification
        'primary_genre_id',
        'track_number',
        'disc_number',

        // Content flags
        'is_explicit',
        'primary_language',
        'languages_sung',
        'contains_local_language',
        'local_genres',
        'cultural_context',
        'mood_tags',

        // Status & visibility - use canonical DB column names
        'status',
        'source_type',
        'source_submission_item_id',
        'is_claimable',
        'visibility',
        'is_featured',
        'is_downloadable',
        'is_streamable',
        'allow_comments',
        'processing_status',

        // Pricing
        'price',
        'is_free',
        'currency',

        // Distribution (CRITICAL for Uganda market)
        'distribution_status',
        'distribution_requested_at',
        'distributed_at',
        'distribution_platforms',

        // Rights management (ISRC critical for distribution)
        'isrc',
        'isrc_code',
        'upc_code',
        'master_ownership_percentage',
        'publishing_ownership_percentage',
        'rights_holders',
        'copyright_year',
        'copyright_holder',
        'license_type',

        // Credits (Ugandan music industry standard)
        'composer',
        'producer',
        'mixing_engineer',
        'mastering_engineer',
        'featured_artists',
        'credits',
        'additional_credits',
        'bpm',
        'key_signature',
        'recording_date',
        'recording_location',
        'recording_studio',

        // Cached aggregates (performance optimization) - use canonical DB column names
        'play_count',
        'unique_listeners_count',
        'download_count',
        'like_count',
        'comment_count',
        'share_count',
        'average_completion_rate',
        'skip_count',
        'revenue_generated',

        // Moderation
        'approved_at',
        'approved_by',
        'review_notes',
        'rejection_reason',
        'flagged_count',
        'moderation_notes',
        'moderated_at',
        'moderated_by',
        'moderation_reason',

        // Release scheduling
        'release_date',
        'scheduled_publish_at',
        'published_at',
    ];

    protected $casts = [
        // Core fields
        'is_free' => 'boolean',
        'is_explicit' => 'boolean',
        'is_featured' => 'boolean',
        'price' => 'decimal:2',
        'duration_seconds' => 'integer',
        'track_number' => 'integer',
        'disc_number' => 'integer',

        // Counts - use canonical DB column names only
        'play_count' => 'integer',
        'unique_listeners_count' => 'integer',
        'download_count' => 'integer',
        'like_count' => 'integer',
        'comment_count' => 'integer',
        'share_count' => 'integer',
        'skip_count' => 'integer',
        'flagged_count' => 'integer',

        // JSON columns
        'featured_artists' => 'array',
        'credits' => 'array',
        'additional_credits' => 'array',
        'waveform_data' => 'array',
        'rights_holders' => 'array',
        'languages_sung' => 'array',
        'local_genres' => 'array',
        'mood_tags' => 'array',
        'distribution_platforms' => 'array',
        'processing_status' => 'array',

        // Decimals - use canonical DB column name
        'master_ownership_percentage' => 'decimal:2',
        'publishing_ownership_percentage' => 'decimal:2',
        'average_completion_rate' => 'decimal:2',
        'revenue_generated' => 'decimal:2',

        // File metadata
        'file_size_bytes' => 'integer',
        'bitrate_original' => 'integer',
        'sample_rate' => 'integer',
        'audio_quality_score' => 'integer',

        // Booleans - use canonical DB column names
        'contains_local_language' => 'boolean',
        'is_claimable' => 'boolean',
        'is_downloadable' => 'boolean',
        'is_streamable' => 'boolean',
        'allow_comments' => 'boolean',

        // Dates
        'release_date' => 'date',
        'recording_date' => 'date',
        'scheduled_publish_at' => 'datetime',
        'published_at' => 'datetime',
        'approved_at' => 'datetime',
        'distribution_requested_at' => 'datetime',
        'distributed_at' => 'datetime',
    ];

    public function getIsrcCodeAttribute($value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $legacyValue = $this->attributes['isrc'] ?? null;

        return is_string($legacyValue) && $legacyValue !== '' ? $legacyValue : null;
    }

    public function setIsrcCodeAttribute($value): void
    {
        $normalized = is_string($value) ? trim($value) : $value;
        $normalized = $normalized === '' ? null : $normalized;

        $this->attributes['isrc_code'] = $normalized;

        if (array_key_exists('isrc', $this->attributes)) {
            $this->attributes['isrc'] = $normalized;
        }
    }

    public function setIsrcAttribute($value): void
    {
        $normalized = is_string($value) ? trim($value) : $value;
        $normalized = $normalized === '' ? null : $normalized;

        $this->attributes['isrc'] = $normalized;
        $this->attributes['isrc_code'] = $normalized;
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function primaryGenre(): BelongsTo
    {
        return $this->belongsTo(Genre::class, 'primary_genre_id');
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'song_genres');
    }

    public function catalogSubmissionItem(): BelongsTo
    {
        return $this->belongsTo(CatalogSubmissionItem::class, 'source_submission_item_id');
    }

    // Singular relationship for backward compatibility
    public function genre(): BelongsToMany
    {
        return $this->genres();
    }

    public function moods(): BelongsToMany
    {
        return $this->belongsToMany(Mood::class, 'song_moods');
    }

    public function playHistory(): HasMany
    {
        return $this->hasMany(PlayHistory::class);
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(Download::class);
    }

    public function isrcCode(): HasOne
    {
        return $this->hasOne(ISRCCode::class);
    }

    public function publishingRights(): HasMany
    {
        return $this->hasMany(PublishingRights::class);
    }

    public function royaltySplits(): HasMany
    {
        return $this->hasMany(RoyaltySplit::class);
    }

    public function playlistSongs(): HasMany
    {
        return $this->hasMany(PlaylistSong::class);
    }

    public function queueItems(): HasMany
    {
        return $this->hasMany(PlayQueue::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(SongDistribution::class);
    }

    public function musicUpload(): HasOne
    {
        return $this->hasOne(MusicUpload::class);
    }

    // Polymorphic relationships
    public function activities()
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function likes()
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    public function shares()
    {
        return $this->morphMany(Share::class, 'shareable');
    }

    public function claimRequests()
    {
        return $this->morphMany(ClaimRequest::class, 'claimable');
    }

    public function pendingClaimRequests()
    {
        return $this->morphMany(ClaimRequest::class, 'claimable')
            ->whereIn('status', ['pending', 'under_review']);
    }

    // Scopes
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeFree($query)
    {
        return $query->where('is_free', true);
    }

    public function scopePaid($query)
    {
        return $query->where('is_free', false);
    }

    public function scopeByGenre($query, $genreId)
    {
        return $query->whereHas('genres', function ($q) use ($genreId) {
            $q->where('genre_id', $genreId);
        });
    }

    public function scopeByMood($query, $moodId)
    {
        return $query->whereHas('moods', function ($q) use ($moodId) {
            $q->where('mood_id', $moodId);
        });
    }

    public function scopePopular($query, $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days))
            ->orderBy('play_count', 'desc');
    }

    public function scopeTrending($query, $days = 7)
    {
        return $query->withCount(['playHistory as recent_plays' => function ($q) use ($days) {
            $q->where('played_at', '>=', now()->subDays($days));
        }])->orderBy('recent_plays', 'desc');
    }

    // New distribution scopes
    public function scopeApproved($query)
    {
        return $query->where('distribution_status', 'approved');
    }

    public function scopePendingReview($query)
    {
        return $query->where('distribution_status', 'pending_review');
    }

    public function scopeDistributed($query)
    {
        return $query->where('distribution_status', 'distributed');
    }

    public function scopeByLanguage($query, string $language)
    {
        return $query->where('primary_language', $language)
            ->orWhereJsonContains('languages_sung', $language);
    }

    public function scopeLocalContent($query)
    {
        return $query->where('contains_local_language', true);
    }

    public function scopeByAudioQuality($query, string $quality)
    {
        return match ($quality) {
            'studio' => $query->where('audio_quality_score', '>=', 95),
            'high' => $query->whereBetween('audio_quality_score', [85, 94]),
            'standard' => $query->whereBetween('audio_quality_score', [70, 84]),
            'compressed', 'mobile' => $query->whereBetween('audio_quality_score', [1, 69]),
            default => $query->whereNull('audio_quality_score'),
        };
    }

    public function scopeHighEarning($query, float $threshold = 1000.00)
    {
        return $query->where('revenue_generated', '>=', $threshold);
    }

    // Accessors & Mutators
    public function getDurationFormattedAttribute(): string
    {
        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * BACKWARD COMPATIBILITY ACCESSOR
     * Maps $song->duration to $song->duration_seconds
     * DB column: duration_seconds
     * Note: 'duration' should NOT be in $fillable - this is read-only accessor
     */
    public function getDurationAttribute(): int
    {
        return $this->duration_seconds;
    }

    /**
     * BACKWARD COMPATIBILITY MUTATOR
     * Redirect duration writes to duration_seconds
     */
    public function setDurationAttribute($value): void
    {
        $this->attributes['duration_seconds'] = $value;
    }

    public function getIsLikedByUserAttribute(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        return $this->likes()->where('user_id', auth()->id())->exists();
    }

    public function hasContentViolations(): bool
    {
        return $this->flagged_count > 0;
    }

    public function getLanguagesDisplayAttribute(): string
    {
        if (! $this->languages_sung) {
            return $this->primary_language ?? 'Not specified';
        }

        return implode(', ', $this->languages_sung);
    }

    public function getCollaboratorSplitTotal(): float
    {
        if (! $this->featured_artists || ! is_array($this->featured_artists)) {
            return 0;
        }

        return collect($this->featured_artists)
            ->sum(fn ($artist) => $artist['percentage'] ?? 0);
    }

    public function hasValidRightsSplits(): bool
    {
        $total = $this->master_ownership_percentage + $this->publishing_ownership_percentage;

        return $total <= 200; // 100% master + 100% publishing
    }

    /**
     * Scope to load songs with optimized relationships to prevent N+1 queries
     */
    public function scopeWithOptimizedRelations($query)
    {
        return $query->with([
            'artist:id,stage_name,avatar,is_verified,total_plays_count,total_revenue',
            'album:id,title,artwork',
            'genres:id,name',
            'moods:id,name',
        ]);
    }

    /**
     * Scope: Get featured songs based on manual curation or popularity
     * Note: is_featured IS a database column for admin manual curation
     * This scope provides algorithmic discovery as alternative
     */
    public function scopeFeatured($query, int $minPlayCount = 5000)
    {
        return $query->where('status', 'published')
            ->where(function ($q) use ($minPlayCount) {
                $q->where('is_featured', true)
                    ->orWhere('play_count', '>', $minPlayCount);
            });
    }

    /**
     * Note: is_featured is a database column (boolean) for admin manual curation
     * The accessor has been removed to prevent conflict with the database column
     * Use scopeFeatured() for queries combining manual curation + algorithmic discovery
     */

    /**
     * Scope to load songs with all essential relations for listing
     */
    public function scopeWithEssentialRelations($query)
    {
        return $query->with([
            'artist:id,stage_name,avatar,is_verified',
            'album:id,title,artwork',
        ]);
    }

    /**
     * Scope to load songs with detailed relations for full view
     */
    public function scopeWithDetailedRelations($query)
    {
        return $query->with([
            'artist:id,stage_name,avatar,bio,is_verified,is_trusted',
            'album:id,title,artwork,release_date',
            'genres:id,name,slug',
            'moods:id,name,slug',
            'isrcCode',
            'publishingRights',
            'royaltySplits',
        ]);
    }

    /**
     * Scope to load songs with play history for analytics
     */
    public function scopeWithPlayHistory($query, int $days = 30)
    {
        return $query->with([
            'playHistory' => function ($q) use ($days) {
                $q->where('played_at', '>=', now()->subDays($days))
                    ->select('song_id', 'user_id', 'duration_played_seconds', 'was_completed', 'played_at');
            },
        ]);
    }

    /**
     * Scope to load songs with engagement counts using withCount to prevent N+1
     */
    public function scopeWithEngagementCounts($query)
    {
        return $query->withCount([
            'likes',
            'comments',
            'shares',
            'playHistory as total_plays_count',
            'playHistory as recent_plays_count' => function ($q) {
                $q->where('created_at', '>=', now()->subDays(7));
            },
        ]);
    }

    /**
     * Scope to efficiently load songs for API responses
     */
    public function scopeForApi($query)
    {
        return $query->select([
            'id', 'title', 'slug', 'duration_seconds', 'play_count', 'price', 'is_free',
            'status', 'artwork', 'audio_file_original', 'artist_id', 'album_id', 'created_at',
        ])->with([
            'artist:id,stage_name,avatar,is_verified',
            'album:id,title,artwork',
        ]);
    }

    /**
     * Scope to load popular songs efficiently
     */
    public function scopePopularWithStats($query, $days = 30)
    {
        return $query->select('songs.*')
            ->addSelect([
                'recent_plays' => \App\Models\PlayHistory::selectRaw('COUNT(*)')
                    ->whereColumn('song_id', 'songs.id')
                    ->where('created_at', '>=', now()->subDays($days)),
            ])
            ->orderByDesc('recent_plays')
            ->orderByDesc('play_count');
    }

    /**
     * Scope to efficiently check if songs are liked by user
     */
    public function scopeWithUserLikeStatus($query, $userId = null)
    {
        if (! $userId) {
            $userId = auth()->id();
        }

        if (! $userId) {
            return $query;
        }

        return $query->addSelect([
            'is_liked_by_user' => \App\Models\Like::selectRaw('COUNT(*) > 0')
                ->whereColumn('likeable_id', 'songs.id')
                ->where('likeable_type', self::class)
                ->where('user_id', $userId),
        ]);
    }

    /**
     * Get the route key for the model.
     * Uses slug for clean URLs on frontend, but admin routes can still use ID via explicit binding.
     */
    public function getRouteKeyName(): string
    {
        // Check if the current request is for admin routes - use ID for admin
        $request = request();
        if ($request && str_starts_with($request->path(), 'admin')) {
            return 'id';
        }

        return 'slug';
    }

    /**
     * Resolve route binding using either slug or ID.
     * This allows both /songs/my-song-title and /admin/songs/123 to work.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        // If an integer is passed, search by ID (for admin)
        if (is_numeric($value)) {
            return $this->where('id', $value)->firstOrFail();
        }

        // Otherwise search by slug (for frontend)
        return $this->where('slug', $value)->firstOrFail();
    }

    public function approve(User $approvedBy, string $notes = ''): void
    {
        $this->update([
            'status' => 'published',
            'distribution_status' => 'approved',
            'approved_at' => now(),
            'published_at' => now(),
            'approved_by' => $approvedBy->id,
            'review_notes' => $notes ?: null,
        ]);

        if (config('music.isrc.auto_generate', false) && $this->canAssignIsrc()) {
            app(\App\Services\Music\ISRCService::class)->assignToSong($this);
        }
    }

    public function reject(User $rejectedBy, string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'distribution_status' => 'rejected',
            'rejection_reason' => $reason,
            'moderated_by' => $rejectedBy->id,
            'moderated_at' => now(),
        ]);
    }

    /**
     * Boot method to auto-generate slug
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($song) {
            // Auto-generate UUID
            if (empty($song->uuid)) {
                $song->uuid = (string) \Illuminate\Support\Str::uuid();
            }

            // Auto-generate slug
            if (! $song->slug && $song->title) {
                $song->slug = \Str::slug($song->title);

                // Ensure uniqueness
                $originalSlug = $song->slug;
                $count = 1;
                while (static::withTrashed()->where('slug', $song->slug)->exists()) {
                    $song->slug = $originalSlug.'-'.$count++;
                }
            }
        });
    }
}
