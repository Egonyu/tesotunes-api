<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SaccoGoal extends Model
{
    use HasFactory;

    protected $table = 'sacco_goals';

    protected $fillable = [
        'uuid',
        'member_id',
        'type',
        'title',
        'description',
        'target_amount',
        'current_amount',
        'currency',
        'deadline',
        'status',
        'visibility',
        'monthly_target',
        'auto_deposit',
        'auto_deposit_percentage',
        'credit_conversion_enabled',
        'production_details',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'current_amount' => 'decimal:2',
        'monthly_target' => 'decimal:2',
        'auto_deposit' => 'boolean',
        'auto_deposit_percentage' => 'decimal:2',
        'credit_conversion_enabled' => 'boolean',
        'deadline' => 'date',
        'production_details' => 'array',
    ];

    protected $attributes = [
        'current_amount' => 0,
        'status' => 'active',
        'visibility' => 'private',
        'auto_deposit' => false,
        'credit_conversion_enabled' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $goal) {
            if (empty($goal->uuid)) {
                $goal->uuid = (string) Str::uuid();
            }
        });
    }

    // Relationships

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SaccoGoalTransaction::class, 'goal_id');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // Accessors

    public function getProgressPercentageAttribute(): float
    {
        if ($this->target_amount <= 0) {
            return 0;
        }

        return min(100, round(($this->current_amount / $this->target_amount) * 100, 2));
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0, $this->target_amount - $this->current_amount);
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->current_amount >= $this->target_amount;
    }

    public function getDaysRemainingAttribute(): ?int
    {
        if (! $this->deadline) {
            return null;
        }

        return max(0, now()->diffInDays($this->deadline, false));
    }
}
