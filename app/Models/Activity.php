<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    public $timestamps = false;
    
    protected $fillable = [
        'user_id',
        'type',
        'activity_type',
        'subject_type',
        'subject_id',
        'data',
        'metadata',
        'visibility',
        'created_at',
    ];

    protected $casts = [
        'data' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected $appends = ['comments_count', 'likes_count'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get comments for this activity
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ActivityComment::class);
    }

    /**
     * Get top-level comments only
     */
    public function topLevelComments(): HasMany
    {
        return $this->hasMany(ActivityComment::class)->whereNull('parent_id')->latest();
    }

    /**
     * Get likes for this activity
     */
    public function likes()
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    /**
     * Get shares for this activity
     */
    public function shares()
    {
        return $this->hasMany(Activity::class, 'subject_id')
            ->where('subject_type', self::class)
            ->where('activity_type', 'shared');
    }

    /**
     * Get bookmarks for this activity
     */
    public function bookmarks()
    {
        return $this->morphMany(Like::class, 'likeable')->where('type', 'bookmark');
    }

    /**
     * Comments count accessor
     */
    public function getCommentsCountAttribute(): int
    {
        return $this->comments()->count();
    }

    /**
     * Likes count accessor
     */
    public function getLikesCountAttribute(): int
    {
        return $this->likes()->count();
    }

    /**
     * Check if user has liked this activity
     */
    public function isLikedBy(?User $user): bool
    {
        if (!$user) return false;
        return $this->likes()->where('user_id', $user->id)->exists();
    }

    /**
     * Check if user has bookmarked this activity
     */
    public function isBookmarkedBy(?User $user): bool
    {
        if (!$user) return false;
        return $this->bookmarks()->where('user_id', $user->id)->exists();
    }

    /**
     * Scope for public activities (activities table has no visibility column, all are public)
     */
    public function scopePublic($query)
    {
        return $query; // All activities are public by default
    }

    /**
     * Scope for feed activities (excludes certain types)
     */
    public function scopeForFeed($query)
    {
        return $query->whereIn('type', [
            'song_released',
            'featured_song',
            'released_album',
            'poll',
            'text_post',
            'spotlight',
            'user_joined',
        ]);
    }
}

