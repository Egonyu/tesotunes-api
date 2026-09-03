<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What one rewardable activity pays, and the rules around it.
 *
 * The pricing and regulation surface for everything the platform pays people to
 * do. Read through {@see \App\Services\Credits\RewardRuleService} rather than
 * directly, so limits and campaign windows are applied consistently.
 *
 * This model previously described columns that did not exist — base_rate,
 * max_daily, cooldown_minutes, conditions, plus a legacy set for promotion
 * pricing — while the service queried a third set again. It now names the
 * columns the table actually has.
 */
class CreditRate extends Model
{
    use HasFactory;

    /** Earning activities. */
    public const REFERRAL_SIGNUP = 'referral_signup';

    public const REFERRAL_WELCOME = 'referral_welcome';

    public const DAILY_LOGIN = 'daily_login';

    public const SONG_PLAY_COMPLETE = 'song_play_complete';

    public const SOCIAL_LIKE = 'social_like';

    public const SOCIAL_SHARE = 'social_share';

    public const SOCIAL_COMMENT = 'social_comment';

    public const SOCIAL_FOLLOW = 'social_follow';

    public const PLAYLIST_CREATE = 'playlist_create';

    public const PROFILE_COMPLETE = 'profile_complete';

    public const CONTRIBUTION_TRANSLATION = 'contribution_translation';

    public const CONTRIBUTION_VALIDATION = 'contribution_validation';

    protected $fillable = [
        'activity_type',
        'display_name',
        'credits_per_action',
        'daily_limit',
        'cooldown_minutes',
        'max_per_user_lifetime',
        'starts_at',
        'ends_at',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'credits_per_action' => 'decimal:2',
            'daily_limit' => 'decimal:2',
            'cooldown_minutes' => 'integer',
            'max_per_user_lifetime' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForActivity(Builder $query, string $activity): Builder
    {
        return $query->where('activity_type', $activity);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('activity_type');
    }

    /** Live right now: active, and inside its campaign window if it has one. */
    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    /** Whether this rate is currently payable. */
    public function isLive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        return ! ($this->ends_at && $now->gt($this->ends_at));
    }

    /** A campaign is a rate with a window on it. */
    public function isCampaign(): bool
    {
        return $this->starts_at !== null || $this->ends_at !== null;
    }

    public function label(): string
    {
        return $this->display_name ?: str($this->activity_type)->replace('_', ' ')->title()->toString();
    }
}
