<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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
            'sku' => $this->sku,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'product_type' => $this->product_type,
            'status' => $this->status,

            // Images
            'featured_image' => $this->featured_image,
            'images' => $this->images,

            // Pricing
            'price_ugx' => $this->price_ugx,
            'price_credits' => $this->price_credits,
            'allow_credit_payment' => (bool) $this->allow_credit_payment,
            'allow_hybrid_payment' => (bool) $this->allow_hybrid_payment,

            // Inventory
            'stock_quantity' => (int) ($this->stock_quantity ?? 0),
            'track_inventory' => (bool) $this->track_inventory,
            'is_in_stock' => $this->when(isset($this->stock_quantity), fn () => $this->stock_quantity > 0 || ! $this->track_inventory),

            // Flags
            'is_featured' => (bool) $this->is_featured,
            'is_taxable' => (bool) $this->is_taxable,
            'has_variants' => (bool) $this->has_variants,

            // Stats
            'average_rating' => $this->when($this->average_rating !== null, fn () => (float) $this->average_rating),
            'review_count' => (int) ($this->review_count ?? 0),
            'view_count' => (int) ($this->view_count ?? 0),

            // Relationships
            'store' => new StoreResource($this->whenLoaded('store')),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug ?? null,
            ]),

            // Timestamps
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
