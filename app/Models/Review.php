<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Review extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'reviewable_type',
        'reviewable_id',
        'order_id',
        'rating',
        'title',
        'content',
        'status',
        'is_verified_purchase',
        'helpful_count',
        'not_helpful_count',
        'seller_response',
        'seller_response_at',
        'metadata',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_verified_purchase' => 'boolean',
        'helpful_count' => 'integer',
        'not_helpful_count' => 'integer',
        'seller_response_at' => 'datetime',
        'metadata' => 'array',
    ];

    public static array $reviewableTypes = [
        'song' => \App\Models\Song::class,
        'album' => \App\Models\Album::class,
        'artist' => \App\Models\Artist::class,
        'playlist' => \App\Models\Playlist::class,
        'event' => \App\Models\Event::class,
        'post' => \App\Models\Post::class,
        'award' => \App\Models\Award::class,
        'poll' => \App\Models\Modules\Forum\Poll::class,
        'product' => \App\Modules\Store\Models\Product::class,
        'store' => \App\Modules\Store\Models\Store::class,
        'podcast' => \App\Models\Podcast::class,
        'podcast_episode' => \App\Models\PodcastEpisode::class,
        'forum_topic' => \App\Models\Modules\Forum\ForumTopic::class,
    ];

    public static function resolveReviewableClass(string $type): ?string
    {
        if (isset(static::$reviewableTypes[$type])) {
            return static::$reviewableTypes[$type];
        }

        $class = 'App\\Models\\'.Str::studly($type);

        return class_exists($class) ? $class : null;
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saved(function (Review $review) {
            $review->syncReviewableMetrics();
        });

        static::deleted(function (Review $review) {
            $review->syncReviewableMetrics();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

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

        return $this->user_id === $user->id
            || in_array($user->role, ['admin', 'super_admin', 'moderator'], true);
    }

    public function isHelpfulMarkedBy(?User $user): ?bool
    {
        if (! $user || ! Schema::hasTable('review_helpful_votes')) {
            return null;
        }

        $vote = DB::table('review_helpful_votes')
            ->where('review_id', $this->id)
            ->where('user_id', $user->id)
            ->value('is_helpful');

        return $vote === null ? null : (bool) $vote;
    }

    public function markHelpful(User $user, bool $helpful): void
    {
        if (! Schema::hasTable('review_helpful_votes')) {
            return;
        }

        $existing = DB::table('review_helpful_votes')
            ->where('review_id', $this->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            DB::table('review_helpful_votes')
                ->where('review_id', $this->id)
                ->where('user_id', $user->id)
                ->update([
                    'is_helpful' => $helpful,
                    'updated_at' => now(),
                ]);

            if ((bool) $existing->is_helpful !== $helpful) {
                if ($helpful) {
                    $this->increment('helpful_count');
                    $this->decrement('not_helpful_count');
                } else {
                    $this->decrement('helpful_count');
                    $this->increment('not_helpful_count');
                }
            }

            return;
        }

        DB::table('review_helpful_votes')->insert([
            'review_id' => $this->id,
            'user_id' => $user->id,
            'is_helpful' => $helpful,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($helpful) {
            $this->increment('helpful_count');
        } else {
            $this->increment('not_helpful_count');
        }
    }

    public function syncReviewableMetrics(): void
    {
        $reviewable = $this->reviewable;

        if (! $reviewable || ! isset($reviewable->id)) {
            return;
        }

        $approved = static::query()
            ->where('reviewable_type', $this->reviewable_type)
            ->where('reviewable_id', $this->reviewable_id)
            ->approved();

        $average = round((float) ($approved->avg('rating') ?? 0), 2);
        $count = (int) $approved->count();

        $table = $reviewable->getTable();
        $updates = [];

        if (self::tableHasColumn($table, 'average_rating')) {
            $updates['average_rating'] = $average;
        }

        if (self::tableHasColumn($table, 'rating')) {
            $updates['rating'] = $average;
        }

        if (self::tableHasColumn($table, 'rating_average')) {
            $updates['rating_average'] = $average;
        }

        if (self::tableHasColumn($table, 'review_count')) {
            $updates['review_count'] = $count;
        }

        if (self::tableHasColumn($table, 'reviews_count')) {
            $updates['reviews_count'] = $count;
        }

        if ($updates !== []) {
            $reviewable->forceFill($updates)->saveQuietly();
        }
    }

    private static function tableHasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table.'.'.$column;

        if (! array_key_exists($key, $cache)) {
            $cache[$key] = Schema::hasColumn($table, $column);
        }

        return $cache[$key];
    }
}
