<?php

namespace App\Models\Modules\Forum;

use App\Models\Artist;
use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PollQuestion extends Model
{
    use HasFactory;

    public const TYPE_MULTIPLE_CHOICE = 'multiple_choice';

    public const TYPE_FREE_TEXT = 'free_text';

    public const TYPE_RATING = 'rating';

    public const TYPE_LIKERT = 'likert';

    public const TYPE_RANKING = 'ranking';

    public const TYPES = [
        self::TYPE_MULTIPLE_CHOICE,
        self::TYPE_FREE_TEXT,
        self::TYPE_RATING,
        self::TYPE_LIKERT,
        self::TYPE_RANKING,
    ];

    protected $fillable = [
        'poll_id',
        'position',
        'question_text',
        'description',
        'question_type',
        'is_required',
        'allow_multiple',
        'song_id',
        'artist_id',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_required' => 'boolean',
            'allow_multiple' => 'boolean',
            'settings' => 'array',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class, 'question_id')->orderBy('position');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(PollAnswer::class, 'question_id');
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    // ── State checks ──────────────────────────────────────────────

    public function isChoiceBased(): bool
    {
        return in_array($this->question_type, [
            self::TYPE_MULTIPLE_CHOICE,
            self::TYPE_RANKING,
        ], true);
    }

    public function isScaleBased(): bool
    {
        return in_array($this->question_type, [
            self::TYPE_RATING,
            self::TYPE_LIKERT,
        ], true);
    }

    public function scaleMin(): int
    {
        return (int) ($this->settings['scale_min'] ?? 1);
    }

    public function scaleMax(): int
    {
        return (int) ($this->settings['scale_max'] ?? ($this->question_type === self::TYPE_LIKERT ? 5 : 10));
    }
}
