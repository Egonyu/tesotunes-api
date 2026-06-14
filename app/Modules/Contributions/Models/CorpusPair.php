<?php

namespace App\Modules\Contributions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An accepted, exportable EN<->Ateso pair. This is the product of the pipeline:
 * the corpus that feeds ateso-nlp. Carries provenance + license + quality so
 * exports are versioned and auditable, and never includes PII.
 */
class CorpusPair extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'provenance' => 'array',
            'quality_score' => 'decimal:2',
            'exported_at' => 'datetime',
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

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ContributionSubmission::class, 'contribution_submission_id');
    }
}
