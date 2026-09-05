<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A milestone paid out to an account, once.
 *
 * Only the claim is stored. Progress toward a milestone is counted from
 * users.referrer_id at read time, so there is no counter to drift away from
 * the accounts actually referred — the failure mode that has bitten the
 * credits ledger before.
 */
class ReferralMilestoneClaim extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'referral_milestone_id',
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
        return $this->belongsTo(ReferralMilestone::class, 'referral_milestone_id');
    }
}
