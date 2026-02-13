<?php

namespace App\Models\Modules\Forum;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PollOption extends Model
{
    use HasFactory;

    protected $table = 'poll_options';

    protected $fillable = [
        'poll_id',
        'option_text',
        'image',
        'vote_count',
        'position',
    ];

    protected $casts = [
        'vote_count' => 'integer',
        'position' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class, 'option_id');
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Percentage of total votes.
     */
    public function getPercentageAttribute(): float
    {
        $total = $this->poll?->total_votes ?? 0;

        return $total > 0 ? round(($this->vote_count / $total) * 100, 1) : 0;
    }
}
