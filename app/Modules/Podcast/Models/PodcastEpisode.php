<?php

namespace App\Modules\Podcast\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PodcastEpisode extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'podcast_id',
        'sponsor_id',
        'title',
        'slug',
        'description',
        'episode_number',
        'season_number',
        'audio_file',
        'duration_seconds',
        'artwork',
        'type',
        'is_explicit',
        'status',
        'is_premium',
        'has_preview',
        'preview_duration_seconds',
        'published_date',
        'published_at',
        'listen_count',
    ];

    protected $casts = [
        'published_date' => 'date',
        'published_at' => 'datetime',
        'duration_seconds' => 'integer',
        'preview_duration_seconds' => 'integer',
        'listen_count' => 'integer',
        'is_explicit' => 'boolean',
        'is_premium' => 'boolean',
        'has_preview' => 'boolean',
    ];

    /**
     * Get the podcast that owns the episode
     */
    public function podcast(): BelongsTo
    {
        return $this->belongsTo(Podcast::class);
    }

    /**
     * Get the sponsor for this episode
     */
    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(PodcastSponsor::class, 'sponsor_id');
    }

    /**
     * Get the chapters for the episode
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(PodcastChapter::class);
    }

    /**
     * Get the listens for the episode
     */
    public function listens(): HasMany
    {
        return $this->hasMany(PodcastListen::class);
    }

    /**
     * Get the downloads for the episode
     */
    public function downloads(): HasMany
    {
        return $this->hasMany(PodcastDownload::class);
    }

    /**
     * Scope: Get only published episodes
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')
                     ->whereNotNull('published_at')
                     ->where('published_at', '<=', now());
    }

    /**
     * Scope: Get episodes by season
     */
    public function scopeBySeason($query, int $season)
    {
        return $query->where('season_number', $season);
    }

    /**
     * Scope: Order by episode number
     */
    public function scopeOrderByEpisode($query)
    {
        return $query->orderBy('season_number', 'desc')
                     ->orderBy('episode_number', 'desc');
    }

    /**
     * Scope: Get premium episodes
     */
    public function scopePremium($query)
    {
        return $query->where('is_premium', true);
    }

    /**
     * Scope: Get free episodes
     */
    public function scopeFree($query)
    {
        return $query->where('is_premium', false);
    }

    /**
     * Check if episode is premium
     */
    public function isPremium(): bool
    {
        return $this->is_premium === true;
    }

    /**
     * Check if episode has preview
     */
    public function hasPreview(): bool
    {
        return $this->has_preview === true && $this->preview_duration_seconds > 0;
    }

    /**
     * Check if user can access this episode
     */
    public function canAccess($user): bool
    {
        if (!$this->isPremium()) {
            return true;
        }

        if (!$user) {
            return false;
        }

        // Check if user has premium subscription to the podcast
        return $this->podcast->subscribers()
            ->where('user_id', $user->id)
            ->where('type', 'premium')
            ->where('status', 'active')
            ->exists();
    }
}
