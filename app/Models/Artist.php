<?php

namespace App\Models;

use App\Models\Traits\Featurable;
use App\Traits\HasComments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Artist extends Model implements HasMedia
{
    use Featurable, HasComments, HasFactory, InteractsWithMedia, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Artist $artist) {
            if (empty($artist->uuid)) {
                $artist->uuid = \Illuminate\Support\Str::uuid();
            }
        });
    }

    /**
     * Get the route key for the model.
     * Use slug instead of id for cleaner URLs
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected $fillable = [
        'uuid',
        'user_id',
        'stage_name',
        'slug',
        'bio',
        'avatar',
        'cover_image',
        'primary_genre_id',
        'social_links',
        'status',
        'is_verified',
        'is_trusted',
        'verification_status',
        'verified_at',
        'verified_by',
        'rejection_reason',
        'can_upload',
        'monthly_upload_limit',
        'total_songs_count',
        'total_albums_count',
        'total_plays_count',
        'total_plays_cached',
        'total_revenue',
        'total_revenue_cached',
        'followers_count',
        'earnings_balance',
        'payout_phone_number',
        'stats_last_updated_at',
        'commission_rate',
        'auto_publish',
        'require_approval',
        'distribution_suspended',
        'website_url',
        'influences',
        'career_start_year',
        'record_label',
    ];

    protected $casts = [
        'social_links' => 'array',
        'influences' => 'array',
        'is_verified' => 'boolean',
        'is_trusted' => 'boolean',
        'can_upload' => 'boolean',
        'auto_publish' => 'boolean',
        'require_approval' => 'boolean',
        'distribution_suspended' => 'boolean',
        'verified_at' => 'datetime',
        'stats_last_updated_at' => 'datetime',
        'total_plays_count' => 'integer',
        'total_plays_cached' => 'integer',
        'total_songs_count' => 'integer',
        'total_albums_count' => 'integer',
        'followers_count' => 'integer',
        'monthly_upload_limit' => 'integer',
        'career_start_year' => 'integer',
        'total_revenue' => 'decimal:2',
        'total_revenue_cached' => 'decimal:2',
        'earnings_balance' => 'decimal:2',
        'commission_rate' => 'decimal:2',
    ];

    /**
     * Register media collections for Spatie Media Library
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection('banner')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection('cover')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function primaryGenre(): BelongsTo
    {
        return $this->belongsTo(Genre::class, 'primary_genre_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function songs(): HasMany
    {
        return $this->hasMany(Song::class);
    }

    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    public function podcasts(): HasMany
    {
        return $this->hasMany(Podcast::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(ArtistProfile::class);
    }

    /**
     * Campaigns where this artist is the beneficiary
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(\App\Modules\Ojokotau\Models\Campaign::class, 'beneficiary_artist_id');
    }

    /**
     * Active support campaigns for this artist
     */
    public function activeCampaigns()
    {
        return $this->campaigns()->where('status', 'active');
    }

    // Polymorphic relationships
    public function followers()
    {
        return $this->morphMany(UserFollow::class, 'followable');
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

    /**
     * Check if the artist is followed by the given user
     */
    public function isFollowedBy(User $user): bool
    {
        return UserFollow::where('follower_id', $user->id)
            ->where('following_id', $this->user_id)
            ->exists();
    }

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

    /**
     * Get the loyalty cards for this artist.
     */
    public function loyaltyCards(): HasMany
    {
        return $this->hasMany(\App\Models\Loyalty\LoyaltyCard::class);
    }

    // Scopes
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopeTrusted($query)
    {
        return $query->where('is_trusted', true);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    // Accessors
    public function getNameAttribute()
    {
        return $this->stage_name;
    }

    /**
     * Get the avatar URL with fallback to default image
     */
    public function getAvatarUrlAttribute(): string
    {
        return \App\Helpers\StorageHelper::avatarUrl($this->avatar, $this->stage_name ?? 'Artist');
    }

    /**
     * Get the banner URL with fallback to null
     */
    public function getBannerUrlAttribute(): ?string
    {
        if (! $this->banner) {
            return null;
        }

        return \App\Helpers\StorageHelper::url($this->banner);
    }

    /**
     * Get the verification status for the artist
     * Returns: pending, verified, rejected, or more_info_required
     */
    public function getVerificationStatusAttribute(): string
    {
        // If rejected, return rejected
        if ($this->status === 'rejected') {
            return 'rejected';
        }

        // If verified, return verified
        if ($this->is_verified) {
            return 'verified';
        }

        // Otherwise pending
        return 'pending';
    }

    public function getFollowerCountAttribute($value)
    {
        // Return the database value directly
        // The column is 'follower_count' which stores the cached count
        return $value ?? 0;
    }

    public function getTotalPlaysAttribute()
    {
        // Always use cached value, queue update if stale
        if ($this->total_plays_count !== null &&
            $this->stats_last_updated_at &&
            $this->stats_last_updated_at->isAfter(now()->subHour())) {
            return $this->total_plays_count;
        }

        // If cache is stale, queue an update but return cached value
        if ($this->total_plays_count !== null) {
            \App\Jobs\UpdateArtistCachedStats::dispatch($this->id)
                ->onQueue('stats')
                ->delay(now()->addMinutes(1)); // Add small delay to batch updates

            return $this->total_plays_count;
        }

        // Only fallback to live calculation if no cached value exists
        return $this->songs()->sum('play_count');
    }

    public function getTotalRevenueAttribute()
    {
        // Get the raw total_revenue column value
        $rawTotal = $this->attributes['total_revenue'] ?? null;

        // Always use cached value, queue update if stale
        if ($rawTotal !== null &&
            $this->stats_last_updated_at &&
            $this->stats_last_updated_at->isAfter(now()->subHour())) {
            return $rawTotal;
        }

        // If cache is stale, queue an update but return cached value
        if ($rawTotal !== null) {
            // Check if UpdateArtistCachedStats job exists before dispatching
            if (class_exists(\App\Jobs\UpdateArtistCachedStats::class)) {
                \App\Jobs\UpdateArtistCachedStats::dispatch($this->id)
                    ->onQueue('stats')
                    ->delay(now()->addMinutes(1)); // Add small delay to batch updates
            }

            return $rawTotal;
        }

        // Only fallback to live calculation if no cached value exists
        return 0;
    }

    /**
     * Calculate monthly listeners (unique users who played songs in last 30 days)
     */
    public function getMonthlyListenersAttribute(): int
    {
        // Cache the monthly listeners count for 1 hour
        $cacheKey = "artist_monthly_listeners_{$this->id}";

        return cache()->remember($cacheKey, now()->addHour(), function () {
            // Get unique users who played this artist's songs in the last 30 days
            return \App\Models\PlayHistory::whereIn('song_id', $this->songs()->pluck('songs.id'))
                ->where('played_at', '>=', now()->subDays(30))
                ->whereNotNull('user_id') // Exclude guest plays
                ->distinct()
                ->count('user_id');
        });
    }

    // Helper methods
    public function canUploadThisMonth(): bool
    {
        return $this->getRemainingUploadsThisMonth() > 0;
    }

    public function getRemainingUploadsAttribute(): int
    {
        return $this->getRemainingUploadsThisMonth();
    }

    /**
     * Get remaining uploads for current month (optimized with caching)
     * Returns 9999 if monthly_upload_limit is null (unlimited)
     */
    public function getRemainingUploadsThisMonth(): int
    {
        // If no limit set, allow unlimited uploads
        if ($this->monthly_upload_limit === null) {
            return 9999;
        }

        $cacheKey = "artist_uploads_{$this->id}_".now()->format('Y_m');

        $currentMonthUploads = cache()->remember($cacheKey, now()->addHour(), function () {
            return $this->songs()
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();
        });

        return max(0, $this->monthly_upload_limit - $currentMonthUploads);
    }

    /**
     * Refresh cached statistics for this artist (optimized)
     */
    public function refreshCachedStats(): void
    {
        // Use a single query with selectRaw to get song stats (only published)
        $songStats = $this->songs()
            ->where('status', 'published')
            ->selectRaw('
                COALESCE(SUM(play_count), 0) as total_plays,
                COUNT(*) as total_songs
            ')
            ->first();

        // Get albums count
        $albumsCount = $this->albums()->count();

        // Get followers count
        $followersCount = $this->followers()->count();

        $this->update([
            'total_plays_count' => $songStats->total_plays ?? 0,
            'total_plays_cached' => $songStats->total_plays ?? 0,
            'total_songs_count' => $songStats->total_songs ?? 0,
            'total_albums_count' => $albumsCount,
            'followers_count' => $followersCount,
            'stats_last_updated_at' => now(),
        ]);

        // Clear upload cache for this artist
        cache()->forget("artist_uploads_{$this->id}_".now()->format('Y_m'));
    }

    /**
     * Get cached stats or calculate them if needed
     */
    public function getCachedStats(): array
    {
        // If stats are stale (older than 1 hour), refresh them
        if (! $this->stats_last_updated_at || $this->stats_last_updated_at->isBefore(now()->subHour())) {
            $this->refreshCachedStats();
        }

        return [
            'total_plays' => $this->total_plays_count,
            'total_revenue' => $this->total_revenue,
            'total_songs' => $this->total_songs_count,
            'total_albums' => $this->total_albums_count,
            'followers_count' => $this->followers_count,
            'last_updated' => $this->stats_last_updated_at,
        ];
    }

    /**
     * Scope to load artists with optimized relationships to prevent N+1
     */
    public function scopeWithOptimizedRelations($query)
    {
        return $query->with([
            'user:id,display_name,email',
            'profile:id,artist_id,bio,location,website',
        ]);
    }

    /**
     * Scope to load artists with song counts to prevent N+1
     */
    public function scopeWithSongCounts($query)
    {
        return $query->withCount([
            'songs',
            'songs as published_songs_count' => function ($q) {
                $q->where('status', 'published');
            },
        ]);
    }

    /**
     * Scope to load artists with fresh stats
     */
    public function scopeWithFreshStats($query)
    {
        return $query->selectRaw('
            artists.*,
            COALESCE(artists.total_plays_count, 0) as total_plays,
            COALESCE(artists.total_revenue, 0) as total_revenue,
            COALESCE(artists.followers_count, 0) as followers_count
        ');
    }

    /**
     * Get notifications for this artist via user relationship
     */
    public function notifications()
    {
        return $this->user->notifications();
    }

    /**
     * Check if artist has distribution rights
     */
    public function hasDistributionRights(): bool
    {
        return $this->is_verified && ! $this->distribution_suspended;
    }

    /**
     * Check if artist profile is completed
     */
    public function hasCompletedProfile(): bool
    {
        return ! empty($this->stage_name) &&
               ! empty($this->bio) &&
               ! empty($this->user_id);
    }
}
