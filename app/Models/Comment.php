<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Comment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'commentable_type',
        'commentable_id',
        'parent_id',
        'content',
        'likes_count',
        'replies_count',
        'status',
    ];

    protected $casts = [
        'likes_count' => 'integer',
        'replies_count' => 'integer',
    ];

    protected $appends = ['is_reply'];

    // ── Commentable type map ─────────────────────────────────────
    // Maps short type names to fully-qualified model classes.
    // Add new commentable models here to make comments plug-and-play.

    public static array $commentableTypes = [
        'song' => \App\Models\Song::class,
        'album' => \App\Models\Album::class,
        'artist' => \App\Models\Artist::class,
        'playlist' => \App\Models\Playlist::class,
        'event' => \App\Models\Event::class,
        'post' => \App\Models\Post::class,
        'activity' => \App\Models\Activity::class,
        'feed_item' => \App\Models\FeedItem::class,
        'award' => \App\Models\Award::class,
        'poll' => \App\Models\Modules\Forum\Poll::class,
        'product' => \App\Modules\Store\Models\Product::class,
        'loyalty_card' => \App\Models\Loyalty\LoyaltyCard::class,
        'campaign' => \App\Models\Campaign::class,
        'campaign_update' => \App\Models\CampaignUpdate::class,
        'podcast' => \App\Models\Podcast::class,
        'podcast_episode' => \App\Models\PodcastEpisode::class,
        'forum_topic' => \App\Models\Modules\Forum\ForumTopic::class,
    ];

    /**
     * Resolve a short type name to its fully-qualified model class.
     * Falls back to App\Models\{Studly} if not in the map.
     */
    public static function resolveCommentableClass(string $type): ?string
    {
        if (isset(static::$commentableTypes[$type])) {
            return static::$commentableTypes[$type];
        }

        // Fallback: try App\Models\{StudlyCase}
        $class = 'App\\Models\\' . Str::studly($type);

        return class_exists($class) ? $class : null;
    }

    // ── Boot ─────────────────────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        static::created(function (Comment $comment) {
            // Increment parent model comments_count (safely — column may not exist)
            try {
                if ($comment->commentable && method_exists($comment->commentable, 'increment')) {
                    $comment->commentable->increment('comments_count');
                }
            } catch (\Illuminate\Database\QueryException $e) {
                // Column doesn't exist on this commentable — skip silently
            }

            // If it's a reply, increment parent comment's replies_count
            if ($comment->parent_id) {
                Comment::where('id', $comment->parent_id)->increment('replies_count');
            }
        });

        static::deleting(function (Comment $comment) {
            try {
                if ($comment->commentable && method_exists($comment->commentable, 'decrement')) {
                    $comment->commentable->decrement('comments_count');
                }
            } catch (\Illuminate\Database\QueryException $e) {
                // Column doesn't exist on this commentable — skip silently
            }

            if ($comment->parent_id) {
                Comment::where('id', $comment->parent_id)->decrement('replies_count');
            }
        });
    }

    // ── Relationships ────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->latest();
    }

    public function likes()
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    // ── Scopes ───────────────────────────────────────────────────

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeOrdered($query)
    {
        return $query->orderByDesc('is_pinned')->latest();
    }

    // ── Accessors ────────────────────────────────────────────────

    public function getIsReplyAttribute(): bool
    {
        return $this->parent_id !== null;
    }

    // ── Authorization Helpers ────────────────────────────────────

    public function canBeEditedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->user_id === $user->id;
    }

    public function canBeDeletedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        // Owner can delete, or admin/moderator
        return $this->user_id === $user->id
            || in_array($user->role, ['admin', 'super_admin', 'moderator'], true);
    }

    // ── Helpers ──────────────────────────────────────────────────

    public function isLikedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->likes()->where('user_id', $user->id)->exists();
    }

    public function toggleLike(User $user): bool
    {
        return Like::toggle($user, $this);
    }

    /**
     * Add a reply to this comment.
     */
    public function addReply(User $user, string $content): self
    {
        return self::create([
            'user_id' => $user->id,
            'commentable_type' => $this->commentable_type,
            'commentable_id' => $this->commentable_id,
            'parent_id' => $this->id,
            'content' => $content,
            'status' => 'approved',
        ]);
    }
}
