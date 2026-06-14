<?php

namespace App\Modules\Contributions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A unit of contribution work: translate a sentence, or validate a peer's
 * submission. Gold tasks carry a hidden known-answer used to score contributors.
 */
class ContributionTask extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const TYPE_TRANSLATE = 'translate';

    public const TYPE_TRANSCRIBE = 'transcribe'; // reserved for v2

    public const TYPE_VALIDATE = 'validate';

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CLOSED = 'closed';

    protected $guarded = ['id'];

    /**
     * gold_answer is sensitive (it would leak the gold-standard answer key) and
     * is never serialised to API responses.
     */
    protected $hidden = ['gold_answer'];

    protected function casts(): array
    {
        return [
            'is_gold' => 'boolean',
            'redundancy_target' => 'integer',
            'submission_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ContributionSubmission::class);
    }

    public function isGold(): bool
    {
        return (bool) $this->is_gold;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
