<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => (int) $this->rating,
            'title' => $this->title,
            'review' => $this->review,
            'status' => $this->status,
            'images' => $this->images,

            // Verification
            'is_verified_purchase' => (bool) $this->is_verified_purchase,

            // Helpfulness
            'helpful_count' => (int) ($this->helpful_count ?? 0),
            'not_helpful_count' => (int) ($this->not_helpful_count ?? 0),

            // Seller response
            'seller_response' => $this->seller_response,
            'seller_response_at' => $this->seller_response_at?->toIso8601String(),

            // Relationships
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'username' => $this->user->username,
            ]),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
            ]),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
