<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Traits\Featurable;

class Album extends Model
{
    use HasFactory, SoftDeletes, Featurable;

    protected $fillable = [
        'user_id',
        'artist_id',
        'title',
        'slug',
        'description',
        'artwork',
        'album_type',
        'price',
        'is_free',
        'is_explicit',
        'status',
        'visibility',
        'release_date',
        'release_year',
        'scheduled_at',
        'credits',
        'record_label',
        'copyright_notice',
        'total_tracks',
        'total_duration_seconds',
        'play_count',
        'download_count',
        'like_count',
        'approved_at',
        'approved_by',
        'rejection_reason',
        'review_notes',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_free' => 'boolean',
        'is_explicit' => 'boolean',
        'credits' => 'array',
        'total_tracks' => 'integer',
        'total_duration' => 'integer',
        'play_count' => 'integer',
        'download_count' => 'integer',
        'release_date' => 'datetime',
        'scheduled_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($album) {
            if (empty($album->uuid)) {
                $album->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    // Relationships
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function songs(): HasMany
    {
        return $this->hasMany(Song::class)->orderBy('track_number');
    }

    // NEW: Rights management
    public function upcCode(): HasOne
    {
        return $this->hasOne(UPCCode::class);
    }

    public function primaryGenre(): BelongsTo
    {
        return $this->belongsTo(Genre::class, 'primary_genre_id');
    }

    // Polymorphic relationships
    public function activities()
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
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

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now());
    }

    // Accessors
    public function getArtworkUrlAttribute(): ?string
    {
        return \App\Helpers\StorageHelper::artworkUrl($this->artwork, '/images/default-album-artwork.svg');
    }

    public function getTotalDurationFormattedAttribute(): string
    {
        $hours = floor($this->total_duration / 3600);
        $minutes = floor(($this->total_duration % 3600) / 60);
        $seconds = $this->total_duration % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }
        return sprintf('%d:%02d', $minutes, $seconds);
    }

    public function getIsLikedByUserAttribute(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        return $this->likes()->where('user_id', auth()->id())->exists();
    }

    // Helper methods
    public function updateCounts(): void
    {
        $this->update([
            'total_tracks' => $this->songs()->count(),
            'total_duration' => $this->songs()->sum('duration'),
            'play_count' => $this->songs()->sum('play_count'),
        ]);
    }

    public function isAvailableForDownload(): bool
    {
        return $this->is_free && $this->status === 'published';
    }

    public function canBeDownloadedAsBatch(): bool
    {
        return $this->isAvailableForDownload() && $this->songs()->count() > 1;
    }

    // Generate downloadable ZIP for offline use (African market feature)
    public function getDownloadableTracksAttribute(): array
    {
        return $this->songs()
            ->where('status', 'published')
            ->where('is_free', true)
            ->get()
            ->map(function ($song) {
                return [
                    'id' => $song->id,
                    'title' => $song->title,
                    'audio_url' => $song->audio_url,
                    'compressed_url' => $song->compressed_audio_url,
                    'artwork' => $song->artwork_url,
                    'duration' => $song->duration,
                ];
            })
            ->toArray();
    }

    // Backward compatibility accessor for 'type' field
    // Migration uses 'album_type' column
    public function getTypeAttribute()
    {
        return $this->attributes['album_type'] ?? null;
    }
}