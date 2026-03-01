<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'type',
        'points',
        'balance_after',
        'source',
        'source_id',
        'source_type',
        'description',
        'base_points',
        'multiplier',
        'created_at',
    ];

    protected $casts = [
        'points' => 'integer',
        'balance_after' => 'integer',
        'base_points' => 'integer',
        'multiplier' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeEarned($query)
    {
        return $query->where('type', 'earned');
    }

    public function scopeSpent($query)
    {
        return $query->where('type', 'spent');
    }

    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }
}
