<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaccoBoardMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'position',
        'term_start_date',
        'term_end_date',
        'is_active',
    ];

    protected $casts = [
        'term_start_date' => 'date',
        'term_end_date' => 'date',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    // Relationships
    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByPosition($query, string $position)
    {
        return $query->where('position', $position);
    }

    public function scopeCurrentTerm($query)
    {
        return $query->where('is_active', true)
            ->where('term_start_date', '<=', now())
            ->where('term_end_date', '>=', now());
    }

    // Accessors
    public function getIsCurrentTermAttribute(): bool
    {
        return $this->is_active
            && $this->term_start_date->isPast()
            && $this->term_end_date->isFuture();
    }

    public function getTermDurationAttribute(): int
    {
        return $this->term_start_date->diffInMonths($this->term_end_date);
    }

    public function getPositionDisplayAttribute(): string
    {
        return ucwords(str_replace('_', ' ', $this->position));
    }
}
