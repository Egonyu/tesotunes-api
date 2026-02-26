<?php

namespace App\Models\Loyalty;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRewardRedemption extends Model
{
    protected $fillable = [
        'loyalty_reward_id',
        'user_id',
        'loyalty_card_member_id',
        'status',
        'fulfilled_at',
        'fulfilment_notes',
    ];

    protected $casts = [
        'fulfilled_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class, 'loyalty_reward_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCardMember::class, 'loyalty_card_member_id');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeFulfilled($query)
    {
        return $query->where('status', 'fulfilled');
    }
}
