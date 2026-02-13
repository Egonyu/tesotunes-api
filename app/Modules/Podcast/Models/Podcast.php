<?php

namespace App\Modules\Podcast\Models;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Podcast extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Database\Factories\Modules\Podcast\Models\PodcastFactory::new();
    }

    protected $fillable = [
        'artist_id',
        'user_id',
        'podcast_category_id',
        'title',
        'slug',
        'description',
        'artwork',
        'rss_feed_url',
        'uuid',
        'rss_guid',
        'author_name',
        'copyright',
        'tags',
        'language',
        'is_explicit',
        'status',
        'is_premium',
        'is_monetized',
        'monetization_type',
        'subscription_price',
        'accepts_sponsorship',
        'total_revenue',
        'monetized_at',
        'total_episodes',
        'subscriber_count',
        'total_listen_count',
    ];

    protected $casts = [
        'is_explicit' => 'boolean',
        'is_premium' => 'boolean',
        'is_monetized' => 'boolean',
        'accepts_sponsorship' => 'boolean',
        'subscription_price' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'monetized_at' => 'datetime',
        'total_episodes' => 'integer',
        'subscriber_count' => 'integer',
        'total_listen_count' => 'integer',
        'tags' => 'array',
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
     * Get the collaborators for the podcast
     */
    public function collaborators()
    {
        return $this->hasMany(PodcastCollaborator::class);
    }

    /**
     * Get the listens for the podcast
     */
    public function listens()
    {
        return $this->hasMany(PodcastListen::class);
    }

    /**
     * Get the downloads for the podcast
     */
    public function downloads()
    {
        return $this->hasMany(PodcastDownload::class);
    }

    /**
     * Get the sponsors for the podcast
     */
    public function sponsors()
    {
        return $this->hasMany(PodcastSponsor::class);
    }

    /**
     * Scope: Get only published podcasts
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope: Get draft podcasts
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope: Filter by category
     */
    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('podcast_category_id', $categoryId);
    }

    /**
     * Scope: Get podcasts with episodes
     */
    public function scopeWithEpisodes($query)
    {
        return $query->has('episodes');
    }

    /**
     * Scope: Get premium podcasts
     */
    public function scopePremium($query)
    {
        return $query->where('is_premium', true);
    }

    /**
     * Scope: Get free podcasts
     */
    public function scopeFree($query)
    {
        return $query->where('is_premium', false);
    }

    /**
     * Scope: Get monetized podcasts
     */
    public function scopeMonetized($query)
    {
        return $query->where('is_monetized', true);
    }

    /**
     * Scope: Filter by monetization type
     */
    public function scopeByMonetizationType($query, $type)
    {
        return $query->where('monetization_type', $type);
    }

    /**
     * Scope: Get podcasts accepting sponsorship
     */
    public function scopeAcceptingSponsorship($query)
    {
        return $query->where('accepts_sponsorship', true);
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
     * Check if podcast is owned by the given user
     */
    public function isOwnedBy($user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Check if user is a collaborator on this podcast
     */
    public function hasCollaborator($user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;
        return $this->collaborators()->where('user_id', $userId)->exists();
    }

    /**
     * Check if podcast is premium
     */
    public function isPremium(): bool
    {
        return $this->is_premium === true;
    }

    /**
     * Check if podcast is monetized
     */
    public function isMonetized(): bool
    {
        return $this->is_monetized === true;
    }

    /**
     * Check if podcast accepts sponsorships
     */
    public function acceptsSponsorship(): bool
    {
        return $this->accepts_sponsorship === true;
    }

    /**
     * Get active sponsors
     */
    public function activeSponsors()
    {
        return $this->sponsors()->where('status', 'active');
    }

    /**
     * Get premium subscribers count
     */
    public function getPremiumSubscribersCountAttribute()
    {
        return $this->subscribers()->wherePivot('type', 'premium')->count();
    }

    /**
     * Get free subscribers count
     */
    public function getFreeSubscribersCountAttribute()
    {
        return $this->subscribers()->wherePivot('type', 'free')->count();
    }
}
