<?php

namespace App\Models\Modules\Forum;

use App\Models\User;
use App\Traits\HasComments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Poll extends Model
{
    use HasComments, HasFactory, SoftDeletes;

    public const TYPE_GENERAL = 'general';

    public const TYPE_SONG_BATTLE = 'song_battle';

    public const TYPE_ARTIST_CONTEST = 'artist_contest';

    public const TYPE_RESEARCH_SURVEY = 'research_survey';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_ARCHIVED = 'archived';

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_USERS = 'users';

    public const AUDIENCE_ARTISTS = 'artists';

    public const CATEGORIES = [
        'general' => 'General',
        'song_battle' => 'Song Battle',
        'artist_contest' => 'Artist Contest',
        'ateso_vs_english' => 'Ateso vs English',
        'district_showdown' => 'District Showdown',
        'traditional_vs_modern' => 'Traditional vs Modern',
        'rising_star' => 'Rising Star',
        'weekly_favorite' => 'Weekly Favorite',
        'genre_face_off' => 'Genre Face-Off',
        'fan_choice' => 'Fan Choice',
        'research' => 'Research Survey',
    ];

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'poll_type',
        'category',
        'audience',
        'allow_guest_responses',
        'show_results_before_completion',
        'is_anonymous',
        'credits_reward',
        'starts_at',
        'ends_at',
        'total_responses',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'allow_guest_responses' => 'boolean',
            'show_results_before_completion' => 'boolean',
            'is_anonymous' => 'boolean',
            'credits_reward' => 'integer',
            'total_responses' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(PollQuestion::class)->orderBy('position');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(PollResponse::class);
    }

    public function answers(): HasManyThrough
    {
        return $this->hasManyThrough(PollAnswer::class, PollResponse::class, 'poll_id', 'response_id');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopePublished($query): void
    {
        $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_CLOSED]);
    }

    public function scopeByType($query, string $type): void
    {
        $query->where('poll_type', $type);
    }

    public function scopeByCategory($query, string $category): void
    {
        $query->where('category', $category);
    }

    public function scopeForAudience($query, ?string $audience): void
    {
        if ($audience) {
            $query->where(function ($q) use ($audience) {
                $q->where('audience', self::AUDIENCE_ALL)
                    ->orWhere('audience', $audience);
            });
        }
    }

    // ── State checks ──────────────────────────────────────────────

    public function isActive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }

    public function isResearchSurvey(): bool
    {
        return $this->poll_type === self::TYPE_RESEARCH_SURVEY;
    }

    public function isCommunityPoll(): bool
    {
        return in_array($this->poll_type, [
            self::TYPE_GENERAL,
            self::TYPE_SONG_BATTLE,
            self::TYPE_ARTIST_CONTEST,
        ], true);
    }

    public function isSongBattle(): bool
    {
        return $this->poll_type === self::TYPE_SONG_BATTLE;
    }

    public function isArtistContest(): bool
    {
        return $this->poll_type === self::TYPE_ARTIST_CONTEST;
    }

    // ── Respondent helpers ────────────────────────────────────────

    public function hasUserResponded(int $userId): bool
    {
        return $this->responses()->where('user_id', $userId)->exists();
    }

    public function hasGuestResponded(string $sessionToken): bool
    {
        return $this->responses()
            ->whereNull('user_id')
            ->where('session_token', $sessionToken)
            ->exists();
    }

    public function getUserResponse(int $userId): ?PollResponse
    {
        return $this->responses()->where('user_id', $userId)->first();
    }

    public function getGuestResponse(string $sessionToken): ?PollResponse
    {
        return $this->responses()
            ->whereNull('user_id')
            ->where('session_token', $sessionToken)
            ->first();
    }

    // ── Lifecycle ────────────────────────────────────────────────

    public function close(): void
    {
        $this->update(['status' => self::STATUS_CLOSED]);
    }

    public function archive(): void
    {
        $this->update(['status' => self::STATUS_ARCHIVED]);
    }

    public function activate(): void
    {
        $this->update(['status' => self::STATUS_ACTIVE]);
    }
}
