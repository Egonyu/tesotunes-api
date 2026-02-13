<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AwardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'year' => $this->year,
            'season' => $this->season,
            'artwork' => $this->artwork,
            'banner' => $this->banner,
            'status' => $this->status,
            'visibility' => $this->visibility,

            // Dates
            'nomination_starts_at' => $this->nomination_starts_at?->toIso8601String(),
            'nomination_ends_at' => $this->nomination_ends_at?->toIso8601String(),
            'voting_starts_at' => $this->voting_starts_at?->toIso8601String(),
            'voting_ends_at' => $this->voting_ends_at?->toIso8601String(),
            'ceremony_date' => $this->ceremony_date?->toIso8601String(),

            // Flags
            'allow_public_nominations' => (bool) $this->allow_public_nominations,
            'allow_public_voting' => (bool) $this->allow_public_voting,
            'votes_per_category' => (int) ($this->votes_per_category ?? 1),

            // Computed state
            'is_nomination_open' => $this->isNominationOpen(),
            'is_voting_open' => $this->isVotingOpen(),

            // Counts
            'categories_count' => $this->when(
                $this->categories_count !== null,
                fn () => (int) $this->categories_count
            ),
            'nominations_count' => $this->when(
                $this->nominations_count !== null,
                fn () => (int) $this->nominations_count
            ),

            // Relationships
            'categories' => AwardCategoryResource::collection($this->whenLoaded('categories')),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
