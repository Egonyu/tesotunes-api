<?php

namespace App\Modules\Sacco\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaccoMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'member_number' => $this->member_number,
            'status' => $this->status,
            'member_type' => $this->member_type,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'id_type' => $this->id_type,
            'id_number' => $this->id_number,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,

            // Relationships
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'email' => $this->user->email,
            ]),

            // Aggregates
            'total_savings' => $this->when($this->total_savings !== null, fn () => $this->total_savings),
            'loans_count' => $this->when($this->loans_count !== null, fn () => (int) $this->loans_count),
            'shares' => new SaccoShareResource($this->whenLoaded('shares')),
            'savings_accounts' => SaccoSavingsAccountResource::collection($this->whenLoaded('savingsAccounts')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
