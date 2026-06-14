<?php

namespace App\Modules\Contributions\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A contributor's answer to a task. Holds the raw words and the normalized
 * (house-orthography) form; reward settlement is tracked by flags here but
 * the money lives in the settlements ledger.
 */
class ContributionSubmission extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'agreement_score' => 'decimal:2',
            'is_gold_attempt' => 'boolean',
            'gold_passed' => 'boolean',
            'is_code_switched' => 'boolean',
            'settled' => 'boolean',
            'settled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ContributionTask::class, 'contribution_task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function validations(): HasMany
    {
        return $this->hasMany(ContributionValidation::class);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }
}
