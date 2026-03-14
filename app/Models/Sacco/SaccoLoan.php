<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SaccoLoan extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DISBURSED = 'disbursed';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PAID = 'paid';

    public const STATUS_DEFAULTED = 'defaulted';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'member_id', 'user_id', 'loan_product_id', 'loan_number', 'application_number', 'loan_type',
        'principal_amount_ugx', 'interest_rate', 'total_interest_ugx', 'total_payable_ugx',
        'amount_paid_ugx', 'balance_remaining_ugx', 'tenure_months', 'duration_months',
        'monthly_installment_ugx', 'disbursement_date', 'first_payment_date', 'due_date',
        'maturity_date', 'guarantors_required', 'guarantors_approved', 'purpose',
        'status', 'rejection_reason', 'approved_at', 'approved_by', 'approval_notes',
        'reviewed_at', 'reviewed_by', 'disbursed_at', 'disbursed_by', 'disbursement_method',
        'disbursement_reference', 'disbursement_notes', 'bank_details', 'mobile_money_details',
        'paid_at', 'auto_deduct', 'applied_at',
        'principal_amount', 'interest_amount', 'processing_fee', 'insurance_fee', 'total_amount',
        'amount_paid', 'balance', 'monthly_repayment', 'outstanding_balance', 'term_months',
        'repayment_period_months', 'installments_paid', 'installments_remaining', 'guarantors',
        'applied_date', 'approved_date', 'disbursed_date', 'application_date', 'first_repayment_date',
        'last_repayment_date', 'fully_repaid_at', 'auto_deduct_from_royalties',
        'royalty_deduction_percentage', 'next_payment_date', 'next_payment_amount',
    ];

    protected $casts = [
        'principal_amount_ugx' => 'decimal:2', 'interest_rate' => 'decimal:2',
        'total_interest_ugx' => 'decimal:2', 'total_payable_ugx' => 'decimal:2',
        'amount_paid_ugx' => 'decimal:2', 'balance_remaining_ugx' => 'decimal:2',
        'monthly_installment_ugx' => 'decimal:2', 'tenure_months' => 'integer',
        'duration_months' => 'integer', 'guarantors_required' => 'integer',
        'guarantors_approved' => 'integer', 'disbursement_date' => 'date',
        'first_payment_date' => 'date', 'due_date' => 'date', 'maturity_date' => 'date',
        'approved_at' => 'datetime', 'reviewed_at' => 'datetime', 'disbursed_at' => 'datetime',
        'paid_at' => 'datetime', 'applied_at' => 'datetime', 'bank_details' => 'array',
        'mobile_money_details' => 'array', 'auto_deduct' => 'boolean',
        'principal_amount' => 'decimal:2', 'interest_amount' => 'decimal:2',
        'processing_fee' => 'decimal:2', 'insurance_fee' => 'decimal:2',
        'total_amount' => 'decimal:2', 'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2', 'monthly_repayment' => 'decimal:2',
        'outstanding_balance' => 'decimal:2', 'term_months' => 'integer',
        'repayment_period_months' => 'integer', 'installments_paid' => 'integer',
        'installments_remaining' => 'integer', 'guarantors' => 'array',
        'applied_date' => 'date', 'approved_date' => 'date', 'disbursed_date' => 'date',
        'application_date' => 'date', 'first_repayment_date' => 'date',
        'last_repayment_date' => 'date', 'fully_repaid_at' => 'datetime',
        'auto_deduct_from_royalties' => 'boolean',
        'royalty_deduction_percentage' => 'decimal:2', 'next_payment_date' => 'date',
        'next_payment_amount' => 'decimal:2',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'loan_type' => 'personal',
        'amount_paid_ugx' => 0,
        'amount_paid' => 0,
        'guarantors_required' => 2,
        'guarantors_approved' => 0,
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(SaccoLoanProduct::class, 'loan_product_id');
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(SaccoLoanGuarantor::class, 'loan_id');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(SaccoLoanRepayment::class, 'loan_id');
    }

    public function payments(): HasMany
    {
        return $this->repayments();
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['disbursed', 'active']);
    }

    public function scopeCompleted($query)
    {
        return $query->whereIn('status', [self::STATUS_COMPLETED, self::STATUS_PAID]);
    }

    public function scopeDefaulted($query)
    {
        return $query->where('status', 'defaulted');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('loan_type', $type);
    }

    public function calculateTotals(): void
    {
        $principal = (float) ($this->principal_amount_ugx ?: $this->principal_amount ?: 0);
        $months = (int) ($this->tenure_months ?: $this->term_months ?: $this->repayment_period_months ?: 1);
        $paid = (float) ($this->amount_paid_ugx ?: $this->amount_paid ?: 0);

        $this->principal_amount_ugx = $principal;
        $this->principal_amount = $principal;
        $this->tenure_months = $months;
        $this->duration_months = $months;
        $this->term_months = $months;
        $this->repayment_period_months = $months;

        $this->total_interest_ugx = ($principal * (float) $this->interest_rate * $months) / (100 * 12);
        $this->interest_amount = $this->total_interest_ugx;
        $this->total_payable_ugx = $principal + $this->total_interest_ugx;
        $this->total_amount = $this->total_payable_ugx;
        $this->monthly_installment_ugx = $months > 0 ? $this->total_payable_ugx / $months : 0;
        $this->monthly_repayment = $this->monthly_installment_ugx;
        $this->amount_paid_ugx = $paid;
        $this->amount_paid = $paid;
        $this->balance_remaining_ugx = max(0, $this->total_payable_ugx - $paid);
        $this->balance = $this->balance_remaining_ugx;
        $this->outstanding_balance = $this->balance_remaining_ugx;
    }

    public function hasAllGuarantors(): bool
    {
        return $this->guarantors_approved >= $this->guarantors_required;
    }

    public function isFullyPaid(): bool
    {
        return $this->amount_paid_ugx >= $this->total_payable_ugx;
    }

    public function canRepay(): bool
    {
        return in_array($this->status, [self::STATUS_DISBURSED, self::STATUS_ACTIVE], true);
    }

    public function isOverdue(): bool
    {
        return $this->due_date?->isPast() && $this->canRepay();
    }

    public function calculateLateFee(): float
    {
        if (! $this->isOverdue()) {
            return 0;
        }

        return $this->due_date->diffInDays(now()) * config('sacco.late_fee_per_day', 100);
    }

    public function getAmountAttribute(): float
    {
        return (float) ($this->principal_amount ?: $this->principal_amount_ugx);
    }

    public function setAmountAttribute($value): void
    {
        $this->attributes['principal_amount'] = $value;
        $this->attributes['principal_amount_ugx'] = $value;
    }

    public function getRemainingBalanceAttribute(): float
    {
        return (float) ($this->balance ?: $this->balance_remaining_ugx ?: $this->outstanding_balance);
    }

    public function getMonthlyPaymentAttribute(): float
    {
        return (float) ($this->monthly_repayment ?: $this->monthly_installment_ugx);
    }

    public function getRepaymentProgressAttribute(): float
    {
        $total = (float) ($this->total_amount ?: $this->total_payable_ugx);

        if ($total <= 0) {
            return 0;
        }

        return ((float) ($this->amount_paid ?: $this->amount_paid_ugx) / $total) * 100;
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($loan) {
            if (empty($loan->uuid)) {
                $loan->uuid = (string) Str::uuid();
            }
            if (empty($loan->loan_number)) {
                $loan->loan_number = 'LOAN'.now()->format('Ymd').rand(10000, 99999);
            }
            if (empty($loan->application_number)) {
                $loan->application_number = 'APP'.now()->format('Ymd').rand(10000, 99999);
            }
        });
        static::saving(function ($loan) {
            if ($loan->isDirty([
                'principal_amount_ugx',
                'principal_amount',
                'interest_rate',
                'tenure_months',
                'term_months',
                'repayment_period_months',
                'amount_paid_ugx',
                'amount_paid',
            ])) {
                $loan->calculateTotals();
            }
        });
    }
}
