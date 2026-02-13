<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'store_type' => $this->store_type,
            'status' => $this->status,

            // Branding
            'logo' => $this->logo,
            'banner' => $this->banner,

            // Contact
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,

            // Verification
            'is_verified' => (bool) $this->is_verified,
            'verified_at' => $this->verified_at?->toIso8601String(),

            // Subscription
            'subscription_tier' => $this->subscription_tier,

            // Stats
            'total_products' => (int) ($this->total_products ?? $this->products_count ?? 0),
            'total_orders' => (int) ($this->total_orders ?? 0),
            'rating' => $this->when($this->rating !== null, fn () => (float) $this->rating),
            'review_count' => (int) ($this->review_count ?? 0),

            // Pickup
            'offers_local_pickup' => (bool) $this->offers_local_pickup,
            'pickup_address' => $this->when($this->offers_local_pickup, $this->pickup_address),

            // Owner
            'owner' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'username' => $this->user->username,
            ]),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
