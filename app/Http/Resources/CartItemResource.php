<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'variant_id' => $this->variant_id,
            'quantity' => (int) $this->quantity,
            'payment_preference' => $this->payment_preference,
            'custom_options' => $this->custom_options,
            'notes' => $this->notes,
            'product' => new ProductResource($this->whenLoaded('product')),
        ];
    }
}
