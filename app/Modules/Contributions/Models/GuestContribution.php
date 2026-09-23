<?php

namespace App\Modules\Contributions\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One turn of the guest loop: a sentence, the model's attempt at it, and the
 * visitor's judgement. Holds anonymous work until it is claimed at sign-in.
 *
 * `claimed_by_user_id` and `claimed_at` are deliberately absent from $fillable —
 * ownership is assigned by GuestClaimService, never by request input.
 */
class GuestContribution extends Model
{
    use HasFactory;

    public const VERDICT_CORRECT = 'correct';

    public const VERDICT_WRONG = 'wrong';

    public const VERDICT_SKIPPED = 'skipped';

    public const ORIGIN_TYPED = 'typed';

    public const ORIGIN_SUGGESTED = 'suggested';

    protected $fillable = [
        'session_key',
        'direction',
        'source_text',
        'model_output',
        'verdict',
        'correction',
        'dialect',
        'is_code_switched',
        'origin',
        'ip_hash',
    ];

    protected $casts = [
        'is_code_switched' => 'boolean',
        'claimed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            $row->uuid ??= (string) Str::uuid();
        });
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'claimed_by_user_id');
    }

    /** Rows that carry usable corpus signal — a verdict was recorded. */
    public function scopeJudged($query)
    {
        return $query->whereIn('verdict', [self::VERDICT_CORRECT, self::VERDICT_WRONG]);
    }

    public function scopeUnclaimed($query)
    {
        return $query->whereNull('claimed_at');
    }

    /**
     * The text that should enter the corpus as the human answer: their
     * correction when they gave one, otherwise the model output they endorsed.
     */
    public function humanAnswer(): ?string
    {
        if ($this->verdict === self::VERDICT_WRONG) {
            return filled($this->correction) ? $this->correction : null;
        }

        return $this->verdict === self::VERDICT_CORRECT ? $this->model_output : null;
    }
}
