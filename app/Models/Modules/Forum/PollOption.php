<?php

namespace App\Models\Modules\Forum;

use App\Models\Artist;
use App\Models\Song;
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
        'song_id',
        'artist_id',
        'option_text',
        'image',
        'vote_count',
        'position',
    ];

    protected $casts = [
        'vote_count' => 'integer',
        'position'   => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class, 'option_id');
    }

    // ── Helpers ───────────────────────────────────────────────

    public function getPercentageAttribute(): float
    {
        $total = $this->poll?->total_votes ?? 0;

        return $total > 0 ? round(($this->vote_count / $total) * 100, 1) : 0;
    }
}
