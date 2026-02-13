<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaccoDividend extends Model
{
    use HasFactory;

    protected $fillable = [
        'dividend_year',
        'total_profit',
        'dividend_rate',
        'declaration_date',
        'payment_date',
        'status',
    ];

    protected $casts = [
        'dividend_year' => 'integer',
        'total_profit' => 'decimal:2',
        'dividend_rate' => 'decimal:2',
        'declaration_date' => 'date',
        'payment_date' => 'date',
    ];

    protected $attributes = [
        'status' => 'declared',
    ];

    // Relationships
    public function memberDividends(): HasMany
    {
        return $this->hasMany(SaccoMemberDividend::class, 'dividend_id');
    }

    // Scopes
    public function scopeByYear($query, int $year)
    {
        return $query->where('dividend_year', $year);
    }

    public function scopeDeclared($query)
    {
        return $query->where('status', 'declared');
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

    public function getTotalMembersAttribute(): int
    {
        return $this->memberDividends()->count();
    }

    public function getTotalPaidOutAttribute(): float
    {
        return $this->memberDividends()->sum('dividend_amount');
    }

    public function getPendingPaymentsCountAttribute(): int
    {
        return $this->memberDividends()->where('status', 'pending')->count();
    }
}
