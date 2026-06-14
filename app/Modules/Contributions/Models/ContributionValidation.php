<?php

namespace App\Modules\Contributions\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A peer's verdict on a submission. The collusion guard (no self-validation,
 * no validating accounts you referred) is enforced in the service layer.
 */
class ContributionValidation extends Model
{
    use HasFactory, HasUuids;

    public const VERDICT_AGREE = 'agree';

    public const VERDICT_MINOR_FIX = 'minor_fix';

    // "Correct, but a different dialect/usage than mine" — a valid variant, not
    // an error. Counts toward acceptance like agree, never rejects the work.
    public const VERDICT_VALID_VARIANT = 'valid_variant';

    public const VERDICT_REJECT = 'reject';

    /** Verdicts that count as approval (the submission is acceptable). */
    public const APPROVING_VERDICTS = [self::VERDICT_AGREE, self::VERDICT_MINOR_FIX, self::VERDICT_VALID_VARIANT];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ContributionSubmission::class, 'contribution_submission_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validator_user_id');
    }
}
