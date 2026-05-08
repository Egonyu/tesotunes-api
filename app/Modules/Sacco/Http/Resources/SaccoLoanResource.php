<?php

namespace App\Modules\Sacco\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaccoLoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'loan_number' => $this->loan_number,
            'loan_type' => $this->loan_type,
            'product_name' => $this->loanProduct?->name,
            'status' => $this->status,
            'purpose' => $this->purpose,

            // Amounts
            'principal_amount_ugx' => $this->principal_amount_ugx,
            'principal_amount' => $this->principal_amount_ugx,
            'interest_rate' => $this->interest_rate,
            'total_interest_ugx' => $this->total_interest_ugx,
            'total_payable_ugx' => $this->total_payable_ugx,
            'total_amount' => $this->total_payable_ugx,
            'amount_paid_ugx' => $this->amount_paid_ugx,
            'amount_paid' => $this->amount_paid_ugx,
            'balance_remaining_ugx' => $this->balance_remaining_ugx,
            'outstanding_balance' => $this->balance_remaining_ugx,

            // Terms
            'tenure_months' => (int) $this->tenure_months,
            'duration_months' => (int) $this->tenure_months,
            'monthly_installment_ugx' => $this->monthly_installment_ugx,
            'monthly_payment' => $this->monthly_installment_ugx,

            // Dates
            'disbursement_date' => $this->disbursement_date?->toDateString(),
            'next_payment_date' => $this->first_payment_date?->toDateString(),
            'next_payment_amount' => $this->monthly_installment_ugx,
            'first_payment_date' => $this->first_payment_date?->toDateString(),
            'maturity_date' => $this->maturity_date?->toDateString(),

            // Guarantors
            'guarantors_required' => (int) $this->guarantors_required,
            'guarantors_approved' => (int) $this->guarantors_approved,

            // Rejection
            'rejection_reason' => $this->when($this->status === 'rejected', $this->rejection_reason),

            // Relationships
            'member' => new SaccoMemberResource($this->whenLoaded('member')),
            'repayments' => SaccoTransactionResource::collection($this->whenLoaded('repayments')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
