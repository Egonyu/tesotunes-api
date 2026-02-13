<?php

namespace App\Modules\Sacco\Models;

use Database\Factories\Sacco\SaccoLoanProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaccoLoanProduct extends Model
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return SaccoLoanProductFactory::new();
    }

    protected $fillable = [
        'name',
        'code',
        'description',
        'interest_rate',
        'min_amount',
        'max_amount',
        'min_repayment_months',
        'max_repayment_months',
        'processing_fee_percentage',
        'insurance_fee_percentage',
        'requires_guarantor',
        'min_guarantors',
        'requires_collateral',
        'is_active',
        'eligibility_criteria',
    ];

    protected $casts = [
        'interest_rate' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'processing_fee_percentage' => 'decimal:2',
        'insurance_fee_percentage' => 'decimal:2',
        'requires_guarantor' => 'boolean',
        'requires_collateral' => 'boolean',
        'is_active' => 'boolean',
        'eligibility_criteria' => 'json',
    ];

    // Aliases for cleaner API
    public function getMinPeriodAttribute()
    {
        return $this->min_repayment_months;
    }

    public function getMaxPeriodAttribute()
    {
        return $this->max_repayment_months;
    }

    /**
     * Get loans using this product
     */
    public function loans(): HasMany
    {
        return $this->hasMany(SaccoLoan::class, 'loan_product_id');
    }

    /**
     * Calculate total loan amount including fees
     */
    public function calculateTotalAmount(float $principal): float
    {
        $processingFee = ($principal * $this->processing_fee_percentage) / 100;
        $insuranceFee = ($principal * $this->insurance_fee_percentage) / 100;
        
        // Calculate interest
        $totalInterest = ($principal * $this->interest_rate) / 100;
        
        return $principal + $processingFee + $insuranceFee + $totalInterest;
    }

    /**
     * Calculate monthly repayment
     */
    public function calculateMonthlyRepayment(float $principal, int $months): float
    {
        $totalAmount = $this->calculateTotalAmount($principal);
        return $totalAmount / $months;
    }
}
