<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AwardNominationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'nominee_name' => $this->nominee_name,
            'nominee_artwork' => $this->nominee_artwork,
            'nomination_reason' => $this->nomination_reason,
            'status' => $this->status,
            'is_official' => (bool) $this->is_official,

            // Polymorphic nominee
            'nominee_type' => $this->nominee_type,
            'nominee_id' => $this->nominee_id,

            // Relationships
            'category' => new AwardCategoryResource($this->whenLoaded('category')),
            'nominated_by' => $this->whenLoaded('nominatedBy', fn () => [
                'id' => $this->nominatedBy->id,
                'username' => $this->nominatedBy->username,
            ]),

            // Vote count (only when results are visible)
            'votes_count' => $this->when(
                $this->votes_count !== null,
                fn () => (int) $this->votes_count
            ),

            // Timestamps
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
