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

    protected $fillable = [
        'question_id',
        'option_text',
        'image',
        'position',
        'song_id',
        'artist_id',
        'response_count',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'response_count' => 'integer',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────

    public function question(): BelongsTo
    {
        return $this->belongsTo(PollQuestion::class, 'question_id');
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(PollAnswer::class, 'option_id');
    }

    // ── Computed ──────────────────────────────────────────────────

    public function getPercentageAttribute(): float
    {
        // Use eager-loaded siblings when available to avoid N+1; otherwise
        // aggregate directly by question_id rather than traversing the inverse
        // `question` relation, which is rarely hydrated on a child option and
        // would otherwise trip the lazy-loading guard.
        $total = $this->relationLoaded('question') && $this->question?->relationLoaded('options')
            ? ($this->question->options->sum('response_count') ?: 0)
            : (int) static::query()->where('question_id', $this->question_id)->sum('response_count');

        return $total > 0 ? round(($this->response_count / $total) * 100, 1) : 0.0;
    }
}
