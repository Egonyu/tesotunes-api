<?php

namespace App\Modules\Contributions\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-contributor reputation, running totals, and the one-time data-terms
 * consent record. Reputation (gold pass-rate -> tier) governs reward rate and
 * tie-break rights; consent gates participation.
 */
class ContributorProfile extends Model
{
    use HasFactory, HasUuids;

    public const TIER_NOVICE = 'novice';

    public const TIER_TRUSTED = 'trusted';

    public const TIER_REVIEWER = 'reviewer';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'gold_attempts' => 'integer',
            'gold_passed' => 'integer',
            'gold_pass_rate' => 'decimal:2',
            'submissions_total' => 'integer',
            'submissions_accepted' => 'integer',
            'validations_total' => 'integer',
            'credits_earned_total' => 'integer',
            'is_suspended' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasConsented(): bool
    {
        return $this->consented_at !== null;
    }
}
