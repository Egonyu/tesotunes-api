<?php

namespace App\Models\Modules\Forum;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PollAnswer extends Model
{
    protected $fillable = [
        'response_id',
        'question_id',
        'option_id',
        'answer_text',
        'rating_value',
        'rank_position',
    ];

    protected function casts(): array
    {
        return [
            'rating_value' => 'integer',
            'rank_position' => 'integer',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────

    public function response(): BelongsTo
    {
        return $this->belongsTo(PollResponse::class, 'response_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(PollQuestion::class, 'question_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'option_id');
    }
}
