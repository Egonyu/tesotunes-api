<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credit goal paid out to an account, once.
 *
 * Only the claim is stored. Progress is summed from credit_transactions at
 * read time, so there is no counter to drift from the ledger.
 */
class CreditMilestoneClaim extends Model
{
    protected $fillable = [
        'user_id',
        'credit_milestone_id',
        'credits_awarded',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'credits_awarded' => 'integer',
            'claimed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(CreditMilestone::class, 'credit_milestone_id');
    }
}
