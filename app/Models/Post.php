<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'song_id',
        'content',
        'type',
        'metadata',
        'privacy',
        'visibility',
        'is_featured',
        'is_pinned',
        'likes_count',
        'comments_count',
        'shares_count',
        'views_count',
        'published_at'
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_featured' => 'boolean',
        'is_pinned' => 'boolean',
        'published_at' => 'datetime'
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('order');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class)->whereNull('parent_id');
    }

    public function allComments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    // Scopes
    public function scopePublic($query)
    {
        return $query->where('visibility', 'public');
    }

    public function scopeForUser($query, User $user)
    {
        // Get posts from people the user follows
        $followingIds = $user->following()->pluck('following_id');
        $followingIds->push($user->id); // Include user's own posts

        return $query->whereIn('user_id', $followingIds)
                    ->where(function ($q) use ($user) {
                        $q->where('privacy', 'public')
                          ->orWhere(function ($q2) use ($user) {
                              $q2->where('privacy', 'followers')
                                 ->whereIn('user_id', $user->following()->pluck('following_id'));
                          })
                          ->orWhere('user_id', $user->id); // Always show own posts
                    });
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('following_type', $type);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopePopular($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days))
                    ->orderByDesc('likes_count')
                    ->orderByDesc('comments_count');
    }

    // Accessors
    public function getTimeAgoAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    public function getIsPublishedAttribute(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    public function getSharedContentAttribute()
    {
        if ($this->following_type === 'music' && isset($this->metadata['song_id'])) {
            return Song::find($this->metadata['song_id']);
        }

        if ($this->following_type === 'event_share' && isset($this->metadata['event_id'])) {
            return Event::find($this->metadata['event_id']);
        }

        return null;
    }

    public function getExcerptAttribute(): string
    {
        if (!$this->content) {
            return '';
        }

        return strlen($this->content) > 150
            ? substr($this->content, 0, 150) . '...'
            : $this->content;
    }

    // Helper methods
    public function isLikedBy(User $user): bool
    {
        return $this->likes()->where('user_id', $user->id)->exists();
    }

    public function like(User $user, string $reactionType = 'like'): void
    {
        $existingLike = $this->likes()->where('user_id', $user->id)->first();

        if ($existingLike) {
            if ($existingLike->reaction_type === $reactionType) {
                // Unlike
                $existingLike->delete();
                $this->decrement('likes_count');
            } else {
                // Change reaction type
                $existingLike->update(['reaction_type' => $reactionType]);
            }
        } else {
            // New like
            $this->likes()->create([
                'user_id' => $user->id,
                'reaction_type' => $reactionType
            ]);
            $this->increment('likes_count');
        }
    }

    public function unlike(User $user): void
    {
        $like = $this->likes()->where('user_id', $user->id)->first();
        if ($like) {
            $like->delete();
            $this->decrement('likes_count');
        }
    }

    public function addComment(User $user, string $content, ?int $parentId = null): PostComment
    {
        $comment = $this->allComments()->create([
            'user_id' => $user->id,
            'parent_id' => $parentId,
            'content' => $content
        ]);

        $this->increment('comments_count');

        // Create notification for post owner
        if ($this->user_id !== $user->id) {
            $this->user->notifications()->create([
                'type' => 'post_comment',
                'title' => 'New Comment',
                'message' => "{$user->name} commented on your post",
                'data' => ['post_id' => $this->id, 'comment_id' => $comment->id],
                'action_url' => route('frontend.social.post', $this->id)
            ]);
        }

        return $comment;
    }

    public function share(User $user, ?string $content = null): Post
    {
        $sharedPost = Post::create([
            'user_id' => $user->id,
            'content' => $content,
            'type' => 'share',
            'metadata' => [
                'shared_post_id' => $this->id,
                'original_user_id' => $this->user_id
            ],
            'privacy' => 'public',
            'published_at' => now()
        ]);

        $this->increment('shares_count');

        // Create notification for original post owner
        if ($this->user_id !== $user->id) {
            $this->user->notifications()->create([
                'type' => 'post_shared',
                'title' => 'Post Shared',
                'message' => "{$user->name} shared your post",
                'data' => ['post_id' => $this->id, 'shared_post_id' => $sharedPost->id],
                'action_url' => route('frontend.social.post', $sharedPost->id)
            ]);
        }

        return $sharedPost;
    }

    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    public function publish(): void
    {
        $this->update([
            'published_at' => now()
        ]);
    }

    public function unpublish(): void
    {
        $this->update([
            'published_at' => null
        ]);
    }

    public function pin(): void
    {
        $this->update(['is_pinned' => true]);
    }

    public function unpin(): void
    {
        $this->update(['is_pinned' => false]);
    }

    public function feature(): void
    {
        $this->update(['is_featured' => true]);
    }

    public function unfeature(): void
    {
        $this->update(['is_featured' => false]);
    }

    public function canBeEditedBy(User $user): bool
    {
        return $this->user_id === $user->id || $user->canManageContent($this);
    }

    public function canBeDeletedBy(User $user): bool
    {
        return $this->user_id === $user->id || $user->canManageContent($this);
    }

    // Static methods
    public static function createTextPost(User $user, string $content, string $privacy = 'public'): Post
    {
        return static::create([
            'user_id' => $user->id,
            'content' => $content,
            'type' => 'text',
            'privacy' => $privacy,
            'published_at' => now()
        ]);
    }

    public static function createMusicPost(User $user, Song $song, ?string $content = null, string $privacy = 'public'): Post
    {
        return static::create([
            'user_id' => $user->id,
            'content' => $content,
            'type' => 'music',
            'metadata' => [
                'song_id' => $song->id,
                'song_title' => $song->title,
                'artist_name' => $song->artist->stage_name ?? $song->artist->name
            ],
            'privacy' => $privacy,
            'published_at' => now()
        ]);
    }

    public static function createEventPost(User $user, Event $event, ?string $content = null, string $privacy = 'public'): Post
    {
        return static::create([
            'user_id' => $user->id,
            'content' => $content,
            'type' => 'event_share',
            'metadata' => [
                'event_id' => $event->id,
                'event_title' => $event->title,
                'event_date' => $event->starts_at?->format('Y-m-d')
            ],
            'privacy' => $privacy,
            'published_at' => now()
        ]);
    }
}