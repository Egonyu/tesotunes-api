<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaccoSavingsAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'account_number' => $this->account_number,
            'account_type' => $this->account_type,
            'account_name' => $this->account_name,
            'balance_ugx' => $this->balance_ugx,
            'interest_rate' => $this->interest_rate,
            'accrued_interest_ugx' => $this->accrued_interest_ugx,
            'minimum_balance_ugx' => $this->minimum_balance_ugx,
            'withdrawal_limit_monthly' => $this->withdrawal_limit_monthly,
            'maturity_date' => $this->maturity_date?->toIso8601String(),
            'allow_early_withdrawal' => (bool) $this->allow_early_withdrawal,
            'status' => $this->status,

            'member' => new SaccoMemberResource($this->whenLoaded('member')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
