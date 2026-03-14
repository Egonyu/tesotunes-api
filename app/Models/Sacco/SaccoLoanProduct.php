<?php

namespace App\Models\Sacco;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaccoLoanProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'code',
        'description',
        'loan_type',
        'min_amount',
        'max_amount',
        'interest_rate',
        'default_interest_rate',
        'min_term_months',
        'max_term_months',
        'min_repayment_months',
        'max_repayment_months',
        'processing_fee_percentage',
        'insurance_fee_percentage',
        'requires_guarantor',
        'min_guarantors',
        'requires_collateral',
        'collateral_percentage',
        'min_savings_balance_required',
        'max_loan_to_savings_ratio',
        'grace_period_days',
        'penalty_rate_per_day',
        'eligibility_criteria',
        'required_documents',
        'terms_and_conditions',
        'is_active',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'processing_fee_percentage' => 'decimal:2',
        'insurance_fee_percentage' => 'decimal:2',
        'collateral_percentage' => 'decimal:2',
        'requires_guarantor' => 'boolean',
        'requires_collateral' => 'boolean',
        'eligibility_criteria' => 'array',
        'required_documents' => 'array',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'processing_fee_percentage' => 0,
        'insurance_fee_percentage' => 0,
        'min_guarantors' => 0,
        'is_active' => true,
    ];

    public function getMinDurationMonthsAttribute(): int
    {
        return $this->min_repayment_months ?? $this->min_term_months;
    }

    public function getMaxDurationMonthsAttribute(): int
    {
        return $this->max_repayment_months ?? $this->max_term_months;
    }

    public function getProcessingFeeRateAttribute(): string
    {
        return (string) $this->processing_fee_percentage;
    }

    public function getRequiresGuarantorsAttribute(): bool
    {
        return (bool) $this->requires_guarantor;
    }

    public function getMinSavingsBalanceAttribute(): string
    {
        return (string) ($this->min_savings_balance_required ?? 0);
    }

    public function getMinSharesAttribute(): int
    {
        return (int) data_get($this->eligibility_criteria, 'min_shares', 0);
    }

    public function getMinMembershipMonthsAttribute(): int
    {
        return (int) data_get($this->eligibility_criteria, 'min_membership_months', 0);
    }

    public function getMinCreditScoreAttribute(): int
    {
        return (int) data_get($this->eligibility_criteria, 'min_credit_score', 0);
    }

    // Relationships
    public function loans(): HasMany
    {
        return $this->hasMany(SaccoLoan::class, 'loan_product_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }

    // Business Logic
    public function calculateMonthlyInstallment(float $principalAmount, int $termMonths): float
    {
        if ($termMonths <= 0 || $this->interest_rate <= 0) {
            return $principalAmount / max($termMonths, 1);
        }

        // Monthly interest rate
        $monthlyRate = ($this->interest_rate / 12) / 100;

        // Total interest amount
        $totalInterest = $principalAmount * ($this->interest_rate / 100) * ($termMonths / 12);

        // Total amount to repay
        $totalAmount = $principalAmount + $totalInterest;

        // Monthly installment (simple division)
        return $totalAmount / $termMonths;
    }

    public function calculateTotalInterest(float $principalAmount, int $termMonths): float
    {
        return $principalAmount * ($this->interest_rate / 100) * ($termMonths / 12);
    }

    public function calculateProcessingFee(float $principalAmount): float
    {
        return ($principalAmount * $this->processing_fee_percentage) / 100;
    }

    public function calculateInsuranceFee(float $principalAmount): float
    {
        return ($principalAmount * $this->insurance_fee_percentage) / 100;
    }

    public function calculateTotalLoanCost(float $principalAmount, int $termMonths): array
    {
        $interest = $this->calculateTotalInterest($principalAmount, $termMonths);
        $processingFee = $this->calculateProcessingFee($principalAmount);
        $insuranceFee = $this->calculateInsuranceFee($principalAmount);
        $totalAmount = $principalAmount + $interest;
        $monthlyInstallment = $this->calculateMonthlyInstallment($principalAmount, $termMonths);

        return [
            'principal_amount' => $principalAmount,
            'interest_amount' => $interest,
            'processing_fee' => $processingFee,
            'insurance_fee' => $insuranceFee,
            'total_fees' => $processingFee + $insuranceFee,
            'total_amount' => $totalAmount,
            'monthly_installment' => $monthlyInstallment,
            'term_months' => $termMonths,
            'interest_rate' => $this->interest_rate,
        ];
    }

    public function isEligibleAmount(float $amount): bool
    {
        return $amount >= $this->min_amount && $amount <= $this->max_amount;
    }

    public function isEligibleTerm(int $termMonths): bool
    {
        return $termMonths >= $this->min_term_months && $termMonths <= $this->max_term_months;
    }

    public function isAmountEligible(float $amount): bool
    {
        return $this->isEligibleAmount($amount);
    }

    public function isTermEligible(int $termMonths): bool
    {
        return $this->isEligibleTerm($termMonths);
    }
}
