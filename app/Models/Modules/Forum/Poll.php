<?php

namespace App\Models\Modules\Forum;

use App\Models\User;
use App\Traits\HasComments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Poll extends Model
{
    use HasComments, HasFactory;

    // Poll types
    public const TYPE_GENERAL = 'general';
    public const TYPE_SONG_BATTLE = 'song_battle';
    public const TYPE_ARTIST_CONTEST = 'artist_contest';

    // Teso-region community categories
    public const CATEGORIES = [
        'general'               => 'General',
        'song_battle'           => 'Song Battle',
        'artist_contest'        => 'Artist Contest',
        'ateso_vs_english'      => 'Ateso vs English',
        'district_showdown'     => 'District Showdown',
        'traditional_vs_modern' => 'Traditional vs Modern',
        'rising_star'           => 'Rising Star',
        'weekly_favorite'       => 'Weekly Favorite',
        'genre_face_off'        => 'Genre Face-Off',
        'fan_choice'            => 'Fan Choice',
    ];

    protected $fillable = [
        'user_id',
        'pollable_type',
        'pollable_id',
        'title',
        'description',
        'allow_multiple_votes',
        'show_results_before_vote',
        'is_anonymous',
        'starts_at',
        'ends_at',
        'total_votes',
        'status',
        'poll_type',
        'category',
        'credits_reward',
    ];

    protected $casts = [
        'allow_multiple_votes'    => 'boolean',
        'show_results_before_vote'=> 'boolean',
        'is_anonymous'            => 'boolean',
        'starts_at'               => 'datetime',
        'ends_at'                 => 'datetime',
        'total_votes'             => 'integer',
        'credits_reward'          => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pollable(): MorphTo
    {
        return $this->morphTo();
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('position');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    // ── Scopes ────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('poll_type', $type);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    // ── Helpers ───────────────────────────────────────────────

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
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

    public function userHasVoted(User $user): bool
    {
        return $this->votes()->where('user_id', $user->id)->exists();
    }

    public function getUserVote(User $user)
    {
        return $this->votes()->where('user_id', $user->id)->get();
    }

    public function close(): void
    {
        $this->update(['status' => 'closed']);
    }

    public function isSongBattle(): bool
    {
        return $this->poll_type === self::TYPE_SONG_BATTLE;
    }

    public function isArtistContest(): bool
    {
        return $this->poll_type === self::TYPE_ARTIST_CONTEST;
    }
}
