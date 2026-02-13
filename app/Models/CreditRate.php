<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'activity_type',
        'base_rate',
        'max_daily',
        'cooldown_minutes',
        'is_active',
        'conditions',
        // Legacy fields for backward compatibility
        'display_name',
        'description',
        'cost_credits',
        'duration_days',
        'max_uses_per_user',
        'max_concurrent',
        'sort_order',
    ];

    protected $casts = [
        'base_rate' => 'decimal:2',
        'max_daily' => 'decimal:2',
        'cooldown_minutes' => 'integer',
        'is_active' => 'boolean',
        'conditions' => 'array',
        // Legacy casts
        'cost_credits' => 'integer',
        'duration_days' => 'integer',
        'max_uses_per_user' => 'integer',
        'max_concurrent' => 'integer',
        'sort_order' => 'integer',
    ];

    // Relationships
    public function transactions()
    {
        return $this->hasMany(CreditTransaction::class, 'activity_type', 'activity_type');
    }

    public function featuredContent()
    {
        return $this->hasMany(\App\Models\FeaturedContent::class, 'feature_type', 'activity_type');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByActivity($query, string $activity)
    {
        return $query->where('activity_type', $activity);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('display_name');
    }

    // Override getFillable for backward compatibility with tests
    public function getFillable()
    {
        // Return core fields for tests
        return [
            'activity_type',
            'base_rate',
            'max_daily',
            'cooldown_minutes',
            'is_active',
            'conditions',
        ];
    }

    // Helper methods
    public function updateCost(int $newCost): void
    {
        $this->update(['cost_credits' => $newCost]);
    }

    public function updateRate(float $newRate): void
    {
        $this->update(['base_rate' => $newRate, 'cost_credits' => $newRate]);
    }

    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    public function getDurationDisplay(): string
    {
        if (!$this->duration_days) {
            return 'Permanent';
        }

        if ($this->duration_days === 1) {
            return '1 day';
        }

        return "{$this->duration_days} days";
    }
}