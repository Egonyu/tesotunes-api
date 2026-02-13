<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AwardCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'artwork' => $this->artwork,
            'category_type' => $this->category_type,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,

            // Counts
            'nominations_count' => $this->when(
                $this->nominations_count !== null,
                fn () => (int) $this->nominations_count
            ),

            // Relationships
            'nominations' => AwardNominationResource::collection($this->whenLoaded('nominations')),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
