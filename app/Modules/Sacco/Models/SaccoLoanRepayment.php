<?php

namespace App\Modules\Sacco\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SaccoLoanRepayment extends Model
{
    use HasFactory;

    protected $table = 'sacco_loan_repayments';

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Database\Factories\Sacco\SaccoLoanRepaymentFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function ($repayment) {
            if (empty($repayment->uuid)) {
                $repayment->uuid = (string) Str::uuid();
            }
            if (empty($repayment->payment_code)) {
                $repayment->payment_code = 'REP' . now()->format('YmdHis') . rand(1000, 9999);
            }
        });
    }

    protected $fillable = [
        'loan_id',
        'member_id',
        'payment_code',
        'amount_ugx',
        'principal_paid_ugx',
        'interest_paid_ugx',
        'penalty_paid_ugx',
        'payment_date',
        'due_date',
        'is_early_payment',
        'is_late_payment',
        'payment_method',
        'reference_number',
        'receipt_number',
        'status',
    ];

    protected $casts = [
        'amount_ugx' => 'decimal:2',
        'principal_paid_ugx' => 'decimal:2',
        'interest_paid_ugx' => 'decimal:2',
        'penalty_paid_ugx' => 'decimal:2',
        'payment_date' => 'datetime',
        'due_date' => 'date',
        'is_early_payment' => 'boolean',
        'is_late_payment' => 'boolean',
    ];

    // Aliases for cleaner API
    public function getAmountAttribute()
    {
        return $this->amount_ugx;
    }

    public function setAmountAttribute($value)
    {
        $this->attributes['amount_ugx'] = $value;
    }

    /**
     * Get the loan
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(SaccoLoan::class, 'loan_id');
    }

    /**
     * Get the member
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    /**
     * Check if repayment is overdue
     */
    public function isOverdue(): bool
    {
        return $this->status !== 'paid' && $this->due_date < now();
    }

    /**
     * Get days overdue
     */
    public function getDaysOverdueAttribute(): int
    {
        if (!$this->isOverdue()) {
            return 0;
        }

        return now()->diffInDays($this->due_date);
    }

    /**
     * Get formatted amount due
     */
    public function getFormattedAmountAttribute(): string
    {
        return 'UGX ' . number_format($this->amount_due, 2);
    }
}
