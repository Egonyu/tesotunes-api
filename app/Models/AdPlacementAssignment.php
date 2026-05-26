<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdPlacementAssignment extends Model
{
    protected $fillable = [
        'ad_id',
        'placement_key',
        'priority',
        'weight',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'weight' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    public function placementConfig(): BelongsTo
    {
        return $this->belongsTo(AdPlacementConfig::class, 'placement_key', 'placement_key');
    }

    // ── Query scopes ──────────────────────────────────────────────────────────

    /** Assignments that are toggled on and within their optional schedule. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }
}
