<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaccoShareResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'member_id' => $this->member_id,
            'total_shares' => (int) $this->total_shares,
            'share_value_ugx' => $this->share_value_ugx,
            'total_value_ugx' => $this->total_value_ugx,
            'last_purchase_at' => $this->last_purchase_at?->toIso8601String(),

            'member' => new SaccoMemberResource($this->whenLoaded('member')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
