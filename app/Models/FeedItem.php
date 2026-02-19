<?php

namespace App\Models;

use App\DTOs\Feed\FeedItem as FeedItemDTO;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeedItem extends Model
{
    use SoftDeletes;

    protected $table = 'feed_items';

    protected $fillable = [
        'uuid',
        'type',
        'module',
        'title',
        'body',
        'actor_id',
        'actor_type',
        'actor_name',
        'actor_avatar_url',
        'actor_verified',
        'subject_id',
        'subject_type',
        'media_type',
        'media_url',
        'media_thumbnail_url',
        'media_duration_seconds',
        'likes_count',
        'comments_count',
        'shares_count',
        'views_count',
        'visibility',
        'required_membership',
        'base_rank_boost',
        'is_prestige',
        'has_celebration',
        'is_aggregated',
        'aggregation_count',
        'region',
        'language',
        'tags',
        'actions',
        'extras',
        'published_at',
        'expires_at',
    ];

    protected $casts = [
        'actor_verified' => 'boolean',
        'is_prestige' => 'boolean',
        'has_celebration' => 'boolean',
        'is_aggregated' => 'boolean',
        'base_rank_boost' => 'float',
        'media_duration_seconds' => 'integer',
        'likes_count' => 'integer',
        'comments_count' => 'integer',
        'shares_count' => 'integer',
        'views_count' => 'integer',
        'aggregation_count' => 'integer',
        'tags' => 'array',
        'actions' => 'array',
        'extras' => 'array',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // ── Scopes ───────────────────────────────────────────────────

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published_at', '<=', now())
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeVisible(Builder $query, ?User $user = null): Builder
    {
        if (! $user) {
            return $query->where('visibility', 'public');
        }

        return $query->where(function ($q) use ($user) {
            $q->where('visibility', 'public')
                ->orWhere('visibility', 'members')
                ->orWhere('actor_id', $user->id);
        });
    }

    public function scopeForRegion(Builder $query, string $region): Builder
    {
        return $query->where(function ($q) use ($region) {
            $q->whereNull('region')
                ->orWhere('region', $region);
        });
    }

    public function scopeRanked(Builder $query): Builder
    {
        return $query->orderByDesc('base_rank_boost')
            ->orderByDesc('published_at');
    }

    // ── Relationships ────────────────────────────────────────────

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject()
    {
        return $this->morphTo('subject');
    }

    // ── DTO Conversion ───────────────────────────────────────────

    public function toDTO(?User $viewer = null): FeedItemDTO
    {
        return new FeedItemDTO(
            id: $this->id,
            uuid: $this->uuid,
            type: $this->type,
            module: $this->module,
            title: $this->title,
            body: $this->body,
            actor: [
                'id' => $this->actor_id,
                'type' => $this->actor_type,
                'name' => $this->actor_name,
                'avatar_url' => $this->actor_avatar_url,
                'verified' => $this->actor_verified,
            ],
            media: $this->media_url ? [
                'type' => $this->media_type,
                'url' => $this->media_url,
                'thumbnail_url' => $this->media_thumbnail_url,
                'duration_seconds' => $this->media_duration_seconds,
            ] : null,
            engagement: [
                'likes_count' => $this->likes_count,
                'comments_count' => $this->comments_count,
                'shares_count' => $this->shares_count,
                'views_count' => $this->views_count,
            ],
            tags: $this->tags ?? [],
            actions: $this->actions ?? [],
            extras: $this->extras ?? [],
            isPrestige: $this->is_prestige,
            publishedAt: $this->published_at?->toIso8601String(),
        );
    }
}
