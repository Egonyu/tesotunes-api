<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaccoMemberDividend extends Model
{
    use HasFactory;

    protected $fillable = [
        'dividend_id',
        'member_id',
        'shares_amount',
        'dividend_amount',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'shares_amount' => 'decimal:2',
        'dividend_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    // Relationships
    public function dividend(): BelongsTo
    {
        return $this->belongsTo(SaccoDividend::class, 'dividend_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    // Accessors
    public function getIsPaidAttribute(): bool
    {
        return $this->status === 'paid';
    }

    public function getFormattedAmountAttribute(): string
    {
        return 'UGX ' . number_format($this->dividend_amount, 2);
    }
}
