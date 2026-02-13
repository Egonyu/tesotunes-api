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

    protected $fillable = [
        'member_id', 'loan_number', 'loan_type', 'principal_amount_ugx',
        'interest_rate', 'total_interest_ugx', 'total_payable_ugx',
        'amount_paid_ugx', 'balance_remaining_ugx', 'tenure_months',
        'monthly_installment_ugx', 'disbursement_date', 'first_payment_date',
        'maturity_date', 'guarantors_required', 'guarantors_approved',
        'status', 'purpose', 'rejection_reason',
    ];

    protected $casts = [
        'principal_amount_ugx' => 'decimal:2', 'interest_rate' => 'decimal:2',
        'total_interest_ugx' => 'decimal:2', 'total_payable_ugx' => 'decimal:2',
        'amount_paid_ugx' => 'decimal:2', 'balance_remaining_ugx' => 'decimal:2',
        'monthly_installment_ugx' => 'decimal:2', 'tenure_months' => 'integer',
        'guarantors_required' => 'integer', 'guarantors_approved' => 'integer',
        'disbursement_date' => 'date', 'first_payment_date' => 'date', 'maturity_date' => 'date',
    ];

    protected $attributes = [
        'status' => 'pending', 'amount_paid_ugx' => 0,
        'guarantors_required' => 2, 'guarantors_approved' => 0, 'loan_type' => 'personal',
    ];

    public function member(): BelongsTo { return $this->belongsTo(SaccoMember::class, 'member_id'); }
    public function guarantors(): HasMany { return $this->hasMany(SaccoLoanGuarantor::class, 'loan_id'); }
    public function repayments(): HasMany { return $this->hasMany(SaccoLoanRepayment::class, 'loan_id'); }
    
    public function scopePending($query) { return $query->where('status', 'pending'); }
    public function scopeApproved($query) { return $query->where('status', 'approved'); }
    public function scopeActive($query) { return $query->whereIn('status', ['disbursed', 'active']); }
    public function scopeCompleted($query) { return $query->where('status', 'completed'); }
    public function scopeDefaulted($query) { return $query->where('status', 'defaulted'); }
    public function scopeByType($query, string $type) { return $query->where('loan_type', $type); }

    public function calculateTotals(): void
    {
        $this->total_interest_ugx = ($this->principal_amount_ugx * $this->interest_rate * $this->tenure_months) / (100 * 12);
        $this->total_payable_ugx = $this->principal_amount_ugx + $this->total_interest_ugx;
        $this->monthly_installment_ugx = $this->total_payable_ugx / $this->tenure_months;
        $this->balance_remaining_ugx = $this->total_payable_ugx - $this->amount_paid_ugx;
    }

    public function hasAllGuarantors(): bool { return $this->guarantors_approved >= $this->guarantors_required; }
    public function isFullyPaid(): bool { return $this->amount_paid_ugx >= $this->total_payable_ugx; }

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($loan) {
            if (empty($loan->uuid)) $loan->uuid = (string) Str::uuid();
            if (empty($loan->loan_number)) $loan->loan_number = 'LOAN' . now()->format('Ymd') . rand(10000, 99999);
        });
        static::saving(function ($loan) {
            if ($loan->isDirty(['principal_amount_ugx', 'interest_rate', 'tenure_months'])) $loan->calculateTotals();
        });
    }
}
