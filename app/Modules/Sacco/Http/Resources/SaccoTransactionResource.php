<?php

namespace App\Modules\Sacco\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaccoTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'transaction_code' => $this->transaction_code ?? $this->payment_code ?? null,
            'type' => $this->type ?? $this->transaction_type ?? null,
            'amount_ugx' => $this->amount_ugx ?? $this->amount ?? null,
            'balance_before_ugx' => $this->balance_before_ugx ?? $this->balance_before ?? null,
            'balance_after_ugx' => $this->balance_after_ugx ?? $this->balance_after ?? null,
            'description' => $this->description ?? null,
            'reference_number' => $this->reference_number ?? $this->transaction_reference ?? null,
            'status' => $this->status ?? 'completed',
            'payment_method' => $this->payment_method ?? null,

            // Loan repayment specific
            'principal_paid_ugx' => $this->when($this->principal_paid_ugx !== null, $this->principal_paid_ugx),
            'interest_paid_ugx' => $this->when($this->interest_paid_ugx !== null, $this->interest_paid_ugx),
            'penalty_paid_ugx' => $this->when($this->penalty_paid_ugx !== null, $this->penalty_paid_ugx),
            'is_early_payment' => $this->when($this->is_early_payment !== null, (bool) $this->is_early_payment),
            'is_late_payment' => $this->when($this->is_late_payment !== null, (bool) $this->is_late_payment),

            'payment_date' => $this->payment_date?->toIso8601String() ?? $this->transaction_date?->toIso8601String() ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
