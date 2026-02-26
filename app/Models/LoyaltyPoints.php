<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyPoints extends Model
{
    protected $fillable = [
        'user_id',
        'balance',
        'lifetime_earned',
        'lifetime_spent',
        'current_multiplier',
    ];

    protected $casts = [
        'balance'            => 'integer',
        'lifetime_earned'    => 'integer',
        'lifetime_spent'     => 'integer',
        'current_multiplier' => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class, 'user_id', 'user_id');
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function hasEnough(int $points): bool
    {
        return $this->balance >= $points;
    }
}
