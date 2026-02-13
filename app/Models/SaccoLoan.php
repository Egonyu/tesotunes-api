<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaccoLoan extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'loan_product_id',
        'loan_number',
        'term_months',
        'purpose',
        'guarantors',
        'applied_date',
    ];

    // CRITICAL: Protect financial columns
    protected $guarded = [
        'principal_amount',
        'interest_amount',
        'total_amount',
        'processing_fee',
        'insurance_fee',
        'amount_paid',
        'balance',
        'monthly_installment',
        'installments_paid',
        'installments_remaining',
        'status',
        'approved_date',
        'disbursed_date',
        'due_date',
        'maturity_date',
        'approved_by',
        'disbursed_by',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'processing_fee' => 'decimal:2',
        'insurance_fee' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
        'monthly_installment' => 'decimal:2',
        'guarantors' => 'array',
        'applied_date' => 'date',
        'approved_date' => 'date',
        'disbursed_date' => 'date',
        'due_date' => 'date',
        'maturity_date' => 'date',
    ];

    // Loan statuses
    const STATUS_PENDING = 'pending_approval';
    const STATUS_APPROVED = 'approved';
    const STATUS_DISBURSED = 'disbursed';
    const STATUS_ACTIVE = 'active';
    const STATUS_COMPLETED = 'completed';
    const STATUS_DEFAULTED = 'defaulted';
    const STATUS_WRITTEN_OFF = 'written_off';
    const STATUS_REJECTED = 'rejected';

    /**
     * Relationships
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(SaccoLoanProduct::class, 'loan_product_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_DISBURSED, self::STATUS_ACTIVE]);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeDefaulted($query)
    {
        return $query->where('status', self::STATUS_DEFAULTED);
    }

    public function scopeOverdue($query)
    {
        return $query->whereIn('status', [self::STATUS_DISBURSED, self::STATUS_ACTIVE])
                     ->where('due_date', '<', now());
    }

    /**
     * Business Logic
     */
    public function canRepay(): bool
    {
        return in_array($this->status, [self::STATUS_DISBURSED, self::STATUS_ACTIVE]);
    }

    public function isOverdue(): bool
    {
        return $this->due_date && $this->due_date->isPast() 
            && in_array($this->status, [self::STATUS_DISBURSED, self::STATUS_ACTIVE]);
    }

    public function getDaysOverdue(): int
    {
        if (!$this->isOverdue()) {
            return 0;
        }
        return $this->due_date->diffInDays(now());
    }

    public function calculateLateFee(): float
    {
        if (!$this->isOverdue()) {
            return 0;
        }

        $daysOverdue = $this->getDaysOverdue();
        $lateFeeRate = config('sacco.late_fee_per_day', 100); // UGX per day
        return $daysOverdue * $lateFeeRate;
    }

    public function getProgressPercentage(): float
    {
        if ($this->total_amount <= 0) {
            return 0;
        }
        return ($this->amount_paid / $this->total_amount) * 100;
    }

    /**
     * Accessors
     */
    public function getIsActiveAttribute(): bool
    {
        return in_array($this->status, [self::STATUS_DISBURSED, self::STATUS_ACTIVE]);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->isOverdue();
    }

    public function getRemainingBalanceAttribute(): float
    {
        return max(0, $this->total_amount - $this->amount_paid);
    }

    public function getPaymentProgressAttribute(): float
    {
        return $this->getProgressPercentage();
    }
}
