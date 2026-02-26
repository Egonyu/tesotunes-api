<?php

namespace App\Http\Resources\Loyalty;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyRewardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'type'        => $this->type,

            // Requirements
            'required_tier'  => $this->required_tier,
            'points_amount'  => $this->points_amount,

            // Availability
            'is_active'           => (bool) $this->is_active,
            'max_redemptions'     => $this->max_redemptions,
            'current_redemptions' => (int) ($this->current_redemptions ?? 0),
            'available_from'      => $this->available_from?->toIso8601String(),
            'available_until'     => $this->available_until?->toIso8601String(),

            // Type-specific content
            'content_type'        => $this->when($this->type === 'content', $this->content_type),
            'content_url'         => $this->when($this->type === 'content', $this->content_url),
            'discount_percentage' => $this->when($this->type === 'discount', $this->discount_percentage),
            'experience_type'     => $this->when($this->type === 'experience', $this->experience_type),

            // Computed
            'is_available' => $this->isAvailable(),

            // Loyalty card (when loaded)
            'loyalty_card' => $this->when($this->relationLoaded('loyaltyCard') && $this->loyaltyCard, function () {
                return [
                    'id'   => $this->loyaltyCard->id,
                    'name' => $this->loyaltyCard->name,
                    'slug' => $this->loyaltyCard->slug,
                ];
            }),

            // Event (when loaded for event-tied rewards)
            'event' => $this->when($this->relationLoaded('event') && $this->event, function () {
                return [
                    'id'    => $this->event->id,
                    'title' => $this->event->title,
                    'slug'  => $this->event->slug,
                ];
            }),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
