<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Podcast extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'artist_id',
        'user_id',
        'podcast_category_id',
        'title',
        'slug',
        'description',
        'artwork',
        'cover_image',
        'rss_feed_url',
        'uuid',
        'rss_guid',
        'author_name',
        'copyright',
        'tags',
        'language',
        'is_explicit',
        'explicit_content',
        'is_premium',
        'status',
        'published_at',
        'total_episodes',
        'total_listens',
        'subscriber_count',
        'total_listen_count',
    ];

    protected $casts = [
        'is_explicit' => 'boolean',
        'explicit_content' => 'boolean',
        'is_premium' => 'boolean',
        'total_episodes' => 'integer',
        'subscriber_count' => 'integer',
        'total_listen_count' => 'integer',
        'tags' => 'array',
        'published_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($podcast) {
            if (empty($podcast->uuid)) {
                $podcast->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    /**
     * Get the user that owns the podcast
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the creator of the podcast (alias for user)
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the artist that owns the podcast
     */
    public function artist()
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * Get the category of the podcast
     */
    public function category()
    {
        // Support both podcast_category_id and category_id for backwards compatibility
        if ($this->category_id) {
            return $this->belongsTo(PodcastCategory::class, 'category_id');
        }
        return $this->belongsTo(PodcastCategory::class, 'podcast_category_id');
    }

    /**
     * Get the episodes for the podcast
     */
    public function episodes()
    {
        return $this->hasMany(PodcastEpisode::class);
    }

    /**
     * Get the subscribers for the podcast
     */
    public function subscribers()
    {
        return $this->belongsToMany(User::class, 'podcast_subscriptions')
            ->withTimestamps()
            ->withPivot('subscribed_at');
    }

    /**
     * Get the subscriptions for the podcast
     */
    public function subscriptions()
    {
        return $this->hasMany(PodcastSubscription::class);
    }

    /**
     * Scope: Get only published podcasts
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope: Get only draft podcasts
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope: Get only premium podcasts
     */
    public function scopePremium($query)
    {
        return $query->where('is_premium', true);
    }

    /**
     * Scope: Get podcasts with episodes
     */
    public function scopeWithEpisodes($query)
    {
        return $query->has('episodes');
    }

    /**
     * Scope: Popular podcasts by subscriber count
     */
    public function scopePopular($query, int $minSubscribers = 100)
    {
        return $query->where('subscriber_count', '>', $minSubscribers)
                     ->orderBy('subscriber_count', 'desc');
    }

    /**
     * Scope: Recent podcasts
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days))
                     ->orderBy('created_at', 'desc');
    }

    /**
     * Get published episodes
     */
    public function publishedEpisodes()
    {
        return $this->episodes()->where('status', 'published');
    }

    /**
     * Check if the podcast is owned by a user
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Check if the podcast is published
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Increment episode count
     */
    public function incrementEpisodeCount(): void
    {
        $this->increment('total_episodes');
    }

    /**
     * Decrement episode count
     */
    public function decrementEpisodeCount(): void
    {
        $this->decrement('total_episodes');
    }

    /**
     * Update statistics
     */
    public function updateStatistics(): void
    {
        $this->update([
            'total_episodes' => $this->episodes()->count(),
            'subscriber_count' => $this->subscriptions()->count(),
        ]);
    }
    
    /**
     * Accessor for explicit_content (alias for is_explicit)
     */
    public function getExplicitContentAttribute()
    {
        return $this->is_explicit;
    }
    
    /**
     * Mutator for explicit_content (alias for is_explicit)
     */
    public function setExplicitContentAttribute($value)
    {
        $this->attributes['is_explicit'] = $value;
    }
    
    /**
     * Accessor for cover_image (alias for artwork)
     */
    public function getCoverImageAttribute()
    {
        return $this->artwork;
    }
    
    /**
     * Mutator for cover_image (alias for artwork)
     */
    public function setCoverImageAttribute($value)
    {
        $this->attributes['artwork'] = $value;
    }
    
    /**
     * Accessor for total_listens (alias for total_listen_count)
     */
    public function getTotalListensAttribute()
    {
        return $this->total_listen_count ?? 0;
    }

    /**
     * Get the route key name
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
