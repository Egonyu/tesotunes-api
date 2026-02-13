<?php

namespace App\Services\Store;

use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use Illuminate\Support\Facades\Storage;

/**
 * Product Service
 *
 * Handles business logic for store products
 */
class ProductService
{
    /**
     * Create a new product
     */
    public function createProduct(Store $store, array $data): Product
    {
        // Handle image uploads if provided
        if (isset($data['images'])) {
            $data['images'] = $this->handleImageUploads($data['images']);
        }

        return $store->products()->create($data);
    }

    /**
     * Update an existing product
     */
    public function updateProduct(Product $product, array $data): Product
    {
        // Handle image uploads if provided
        if (isset($data['images'])) {
            $data['images'] = $this->handleImageUploads($data['images']);
        }

        $product->update($data);
        return $product->fresh();
    }

    /**
     * Delete a product
     */
    public function deleteProduct(Product $product): bool
    {
        // Delete associated images
        if ($product->images) {
            foreach ($product->images as $image) {
                Storage::disk(config('store.storage.disk'))->delete($image);
            }
        }

        return $product->delete();
    }

    /**
     * Get product statistics
     */
    public function getProductStatistics(Product $product): array
    {
        return [
            'total_sales' => $product->orderItems()->sum('quantity'),
            'total_revenue' => $product->orderItems()->sum('total_price'),
            'average_rating' => $product->reviews()->avg('rating') ?? 0,
            'reviews_count' => $product->reviews()->count(),
        ];
    }

    /**
     * Check if product is available for purchase
     */
    public function isAvailable(Product $product): bool
    {
        return $product->status === Product::STATUS_ACTIVE
            && $product->store->status === Store::STATUS_ACTIVE
            && ($product->stock_quantity === null || $product->stock_quantity > 0);
    }

    /**
     * Handle image uploads
     */
    private function handleImageUploads(array $images): array
    {
        $uploadedImages = [];

        foreach ($images as $image) {
            if ($image && $image->isValid()) {
                $path = $image->store('store/products', config('store.storage.disk'));
                $uploadedImages[] = $path;
            }
        }

        return $uploadedImages;
    }
}