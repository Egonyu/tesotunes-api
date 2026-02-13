<?php

namespace App\Models\Modules\Forum;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Poll extends Model
{
    use HasFactory;

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
    ];

    protected $casts = [
        'allow_multiple_votes' => 'boolean',
        'show_results_before_vote' => 'boolean',
        'is_anonymous' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'total_votes' => 'integer',
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

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Whether the poll is currently accepting votes.
     */
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

    /**
     * Check whether a user has already voted on this poll.
     */
    public function userHasVoted(User $user): bool
    {
        return $this->votes()->where('user_id', $user->id)->exists();
    }

    /**
     * Get a specific user's vote(s).
     */
    public function getUserVote(User $user)
    {
        return $this->votes()->where('user_id', $user->id)->get();
    }

    /**
     * Close the poll.
     */
    public function close(): void
    {
        $this->update(['status' => 'closed']);
    }
}
