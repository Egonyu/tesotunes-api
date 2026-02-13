<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
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
            'unit_price' => $this->unit_price,
            'total_price' => $this->total_price,
            'payment_preference' => $this->payment_preference,
            'product' => new ProductResource($this->whenLoaded('product')),
        ];
    }
}
