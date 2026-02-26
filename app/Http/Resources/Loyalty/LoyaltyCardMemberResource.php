<?php

namespace App\Http\Resources\Loyalty;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyCardMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'     => $this->id,
            'status' => $this->status,
            'tier'   => $this->tier,

            // Subscription details
            'subscription_type' => $this->subscription_type,
            'auto_renew'        => (bool) $this->auto_renew,

            // Payment
            'price_paid'     => $this->price_paid,
            'payment_method' => $this->payment_method,

            // Dates
            'joined_at'  => $this->joined_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'renewed_at' => $this->renewed_at?->toIso8601String(),

            // Loyalty Card
            'loyalty_card' => $this->when($this->relationLoaded('loyaltyCard') && $this->loyaltyCard, function () {
                return new LoyaltyCardResource($this->loyaltyCard);
            }),

            // User (for artist-side views)
            'user' => $this->when($this->relationLoaded('user') && $this->user, function () {
                return [
                    'id'     => $this->user->id,
                    'name'   => $this->user->name,
                    'email'  => $this->user->email,
                    'avatar' => $this->user->avatar_url ?? null,
                ];
            }),

            // Computed
            'is_active'  => $this->isActive(),
            'is_expired' => $this->isExpired(),
            'tier_level' => $this->tierLevel(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
