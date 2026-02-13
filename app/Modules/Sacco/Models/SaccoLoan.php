<?php

namespace App\Modules\Sacco\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SaccoLoan extends Model
{
    use HasFactory;

    /**
     * Boot method to auto-generate uuid on creating
     */
    protected static function booted(): void
    {
        static::creating(function (SaccoLoan $loan) {
            if (empty($loan->uuid)) {
                $loan->uuid = (string) Str::uuid();
            }
            if (empty($loan->loan_number)) {
                $loan->loan_number = 'LOAN' . now()->format('Ymd') . strtoupper(Str::random(6));
            }
            // Always calculate totals based on principal, rate, and tenure
            static::calculateLoanTotals($loan);
        });

        static::updating(function (SaccoLoan $loan) {
            // Recalculate if principal, rate, or tenure changed
            if ($loan->isDirty(['principal_amount_ugx', 'interest_rate', 'tenure_months'])) {
                static::calculateLoanTotals($loan);
            }
        });
    }

    /**
     * Calculate loan totals (interest, total payable, monthly installment)
     */
    protected static function calculateLoanTotals(SaccoLoan $loan): void
    {
        if ($loan->principal_amount_ugx && $loan->interest_rate && $loan->tenure_months) {
            $interest = ($loan->principal_amount_ugx * $loan->interest_rate * $loan->tenure_months) / (100 * 12);
            $totalPayable = $loan->principal_amount_ugx + $interest;
            
            $loan->total_interest_ugx = $interest;
            $loan->total_payable_ugx = $totalPayable;
            $loan->balance_remaining_ugx = $totalPayable - ($loan->amount_paid_ugx ?? 0);
            $loan->monthly_installment_ugx = $totalPayable / $loan->tenure_months;
        }
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Database\Factories\Sacco\SaccoLoanFactory::new();
    }

    protected $fillable = [
        'member_id',
        'user_id',
        'loan_product_id',
        'loan_number',
        'loan_type',
        'principal_amount_ugx',
        'interest_rate',
        'total_interest_ugx',
        'total_payable_ugx',
        'amount_paid_ugx',
        'balance_remaining_ugx',
        'tenure_months',
        'monthly_installment_ugx',
        'disbursement_date',
        'first_payment_date',
        'maturity_date',
        'guarantors_required',
        'guarantors_approved',
        'purpose',
        'status',
        'rejection_reason',
        'approved_at',
        'approved_by',
        'approval_notes',
        'reviewed_at',
        'reviewed_by',
        'disbursed_at',
        'disbursement_method',
        'disbursement_reference',
        'bank_details',
        'mobile_money_details',
        'paid_at',
        'auto_deduct',
        'applied_at',
    ];

    protected $casts = [
        'principal_amount_ugx' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'total_interest_ugx' => 'decimal:2',
        'total_payable_ugx' => 'decimal:2',
        'amount_paid_ugx' => 'decimal:2',
        'balance_remaining_ugx' => 'decimal:2',
        'monthly_installment_ugx' => 'decimal:2',
        'disbursement_date' => 'date',
        'first_payment_date' => 'date',
        'maturity_date' => 'date',
        'approved_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'paid_at' => 'datetime',
        'applied_at' => 'datetime',
        'auto_deduct' => 'boolean',
    ];

    // Aliases for cleaner API
    public function getAmountAttribute()
    {
        return $this->principal_amount_ugx;
    }

    public function setAmountAttribute($value)
    {
        $this->attributes['principal_amount_ugx'] = $value;
    }

    public function getInterestAmountAttribute()
    {
        return $this->total_interest_ugx;
    }

    public function setInterestAmountAttribute($value)
    {
        $this->attributes['total_interest_ugx'] = $value;
    }

    public function getTotalPayableAttribute()
    {
        return $this->total_payable_ugx;
    }

    public function setTotalPayableAttribute($value)
    {
        $this->attributes['total_payable_ugx'] = $value;
    }

    public function getMonthlyInstallmentAttribute()
    {
        return $this->monthly_installment_ugx;
    }

    public function setMonthlyInstallmentAttribute($value)
    {
        $this->attributes['monthly_installment_ugx'] = $value;
    }

    public function getBalanceAttribute()
    {
        return $this->balance_remaining_ugx;
    }

    public function setBalanceAttribute($value)
    {
        $this->attributes['balance_remaining_ugx'] = $value;
    }

    public function getPeriodMonthsAttribute()
    {
        return $this->tenure_months;
    }

    public function setPeriodMonthsAttribute($value)
    {
        $this->attributes['tenure_months'] = $value;
    }

    /**
     * Get the member that owns the loan
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    /**
     * Get the user that owns the loan
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * Get the loan product
     */
    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(SaccoLoanProduct::class, 'loan_product_id');
    }

    /**
     * Get repayments for this loan
     */
    public function repayments(): HasMany
    {
        return $this->hasMany(SaccoLoanRepayment::class, 'loan_id');
    }

    /**
     * Get guarantors for this loan
     */
    public function guarantors(): HasMany
    {
        return $this->hasMany(SaccoLoanGuarantor::class, 'loan_id');
    }

    /**
     * Check if loan is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if loan is overdue
     */
    public function isOverdue(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $lastRepayment = $this->repayments()->latest('due_date')->first();
        if (!$lastRepayment) {
            return false;
        }

        return $lastRepayment->due_date < now() && $lastRepayment->status !== 'paid';
    }

    /**
     * Get repayment progress percentage
     */
    public function getRepaymentProgressAttribute(): float
    {
        if ($this->total_amount <= 0) {
            return 0;
        }

        return ($this->total_repaid / $this->total_amount) * 100;
    }

    /**
     * Get formatted principal amount
     */
    public function getFormattedPrincipalAttribute(): string
    {
        return 'UGX ' . number_format($this->principal_amount, 2);
    }

    /**
     * Get formatted outstanding balance
     */
    public function getFormattedOutstandingAttribute(): string
    {
        return 'UGX ' . number_format($this->outstanding_balance, 2);
    }

    /**
     * Get status badge color
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'pending' => 'warning',
            'approved' => 'info',
            'active' => 'success',
            'overdue' => 'danger',
            'defaulted' => 'dark',
            'completed' => 'success',
            'rejected' => 'danger',
            default => 'secondary'
        };
    }

    /**
     * Check if loan has all required guarantors approved
     */
    public function hasAllGuarantors(): bool
    {
        return $this->guarantors_approved >= $this->guarantors_required;
    }

    /**
     * Check if loan is fully paid
     */
    public function isFullyPaid(): bool
    {
        return $this->amount_paid_ugx >= $this->total_payable_ugx;
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeDisbursed($query)
    {
        return $query->where('status', 'disbursed');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'disbursed']);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeDefaulted($query)
    {
        return $query->where('status', 'defaulted');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('loan_type', $type);
    }
}
