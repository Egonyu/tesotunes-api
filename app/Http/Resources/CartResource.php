<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'status' => $this->status,

            // Items
            'items' => CartItemResource::collection($this->whenLoaded('items')),
            'items_count' => (int) ($this->items_count ?? $this->whenLoaded('items', fn () => $this->items->count(), 0)),

            // Totals (computed)
            'subtotal_ugx' => $this->when(
                $this->relationLoaded('items'),
                fn () => $this->items->sum(fn ($item) => ($item->product->price_ugx ?? 0) * $item->quantity)
            ),
            'subtotal_credits' => $this->when(
                $this->relationLoaded('items'),
                fn () => $this->items->sum(fn ($item) => ($item->product->price_credits ?? 0) * $item->quantity)
            ),

            // Timestamps
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
