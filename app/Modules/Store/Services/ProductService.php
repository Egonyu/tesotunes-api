<?php

namespace App\Modules\Store\Services;

use App\Modules\Store\Models\{Product, Store};
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

/**
 * ProductService
 * 
 * Handles all business logic for product management
 */
class ProductService
{
    /**
     * Get the storage disk, falling back to 'public' if configured disk is unavailable
     */
    protected function getStorageDisk(): string
    {
        $disk = config('store.storage.disk', 'public');
        
        // Check if the disk is properly configured
        try {
            Storage::disk($disk);
            return $disk;
        } catch (\Exception $e) {
            \Log::warning("Store: Configured disk '{$disk}' is unavailable, falling back to 'public'", [
                'error' => $e->getMessage()
            ]);
            return 'public';
        }
    }

    /**
     * Create a new product
     */
    public function create(Store $store, array $data): Product
    {
        $this->validateProductCreation($store);
        
        return DB::transaction(function () use ($store, $data) {
            $product = Product::create([
                'uuid' => Str::uuid(),
                'store_id' => $store->id,
                'category_id' => $data['category_id'] ?? null,
                'name' => $data['name'],
                'slug' => $this->generateUniqueSlug($data['name'], $store->id),
                'description' => $data['description'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'product_type' => $data['product_type'],
                'price_ugx' => $data['price_ugx'],
                'price_credits' => $data['price_credits'] ?? null,
                'allow_credit_payment' => $data['allow_credit_payment'] ?? true,
                'allow_hybrid_payment' => $data['allow_hybrid_payment'] ?? true,
                'compare_at_price_ugx' => $data['compare_at_price_ugx'] ?? null,
                'sku' => $data['sku'] ?? 'SKU-' . strtoupper(Str::random(8)),
                'inventory_quantity' => $data['inventory_quantity'] ?? 0,
                'track_inventory' => $data['track_inventory'] ?? true,
                'requires_shipping' => $data['requires_shipping'] ?? true,
                'weight' => $data['weight'] ?? null,
                'dimensions' => $data['dimensions'] ?? null,
                'is_digital' => $data['is_digital'] ?? false,
                'download_limit' => $data['download_limit'] ?? null,
                'status' => Product::STATUS_DRAFT,
                'metadata' => $data['metadata'] ?? [],
            ]);
            
            // Handle images
            if (isset($data['images'])) {
                $this->uploadImages($product, $data['images']);
            }
            
            // Handle digital file
            if ($product->is_digital && isset($data['digital_file'])) {
                $this->uploadDigitalFile($product, $data['digital_file']);
            }
            
            return $product;
        });
    }

    /**
     * Update product
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $updateData = array_filter([
                'name' => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'price_ugx' => $data['price_ugx'] ?? null,
                'price_credits' => $data['price_credits'] ?? null,
                'compare_at_price_ugx' => $data['compare_at_price_ugx'] ?? null,
                'inventory_quantity' => $data['inventory_quantity'] ?? null,
                'weight' => $data['weight'] ?? null,
                'dimensions' => $data['dimensions'] ?? null,
                'is_featured' => $data['is_featured'] ?? null,
            ], fn($value) => $value !== null);
            
            if (isset($data['name']) && $data['name'] !== $product->name) {
                $updateData['slug'] = $this->generateUniqueSlug($data['name'], $product->store_id, $product->id);
            }
            
            if (!empty($updateData)) {
                $product->update($updateData);
            }
            
            // Handle images
            if (isset($data['images'])) {
                $this->uploadImages($product, $data['images']);
            }
            
            // Handle digital file
            if ($product->is_digital && isset($data['digital_file'])) {
                $this->uploadDigitalFile($product, $data['digital_file']);
            }
            
            return $product->fresh();
        });
    }

    /**
     * Activate product
     */
    public function activate(Product $product): bool
    {
        return $product->activate();
    }

    /**
     * Archive product
     */
    public function archive(Product $product): bool
    {
        return $product->update(['status' => Product::STATUS_ARCHIVED]);
    }

    /**
     * Update inventory
     */
    public function updateInventory(Product $product, int $quantity, string $operation = 'set'): void
    {
        // Check track_inventory from direct attribute first, then relation
        $trackInventory = array_key_exists('track_inventory', $product->getAttributes()) 
            ? (bool)$product->getAttributes()['track_inventory']
            : ($product->inventory ? ($product->inventory->track_inventory === 'track') : true);
        
        if (!$trackInventory) {
            return;
        }
        
        // Get current quantity from direct attribute first, then relation
        $currentQty = $product->getAttributes()['inventory_quantity'] 
            ?? $product->getAttributes()['stock_quantity']
            ?? ($product->inventory ? $product->inventory->stock_quantity : 0);
        
        $newQuantity = match($operation) {
            'add' => $currentQty + $quantity,
            'subtract' => max(0, $currentQty - $quantity),
            'set' => $quantity,
        };
        
        // Update inventory and status
        $updates = [
            'inventory_quantity' => $newQuantity,
            'stock_quantity' => $newQuantity, // Also update stock_quantity for compatibility
        ];
        
        // Check allow_backorder from direct attribute first
        $allowBackorder = array_key_exists('allow_backorder', $product->getAttributes())
            ? (bool)$product->getAttributes()['allow_backorder']
            : ($product->inventory ? $product->inventory->allow_backorder : false);
        
        // Mark as out of stock if inventory reaches zero
        if ($newQuantity <= 0 && !$allowBackorder) {
            $updates['status'] = 'out_of_stock';
        } elseif ($newQuantity > 0 && $product->status === 'out_of_stock') {
            // Restore to active if inventory is replenished
            $updates['status'] = 'active';
        }
        
        $product->update($updates);
        
        // Also update the inventory relation if it exists
        if ($product->inventory) {
            $product->inventory->update([
                'stock_quantity' => $newQuantity,
                'quantity' => $newQuantity,
                'available_quantity' => $newQuantity,
                'is_in_stock' => $newQuantity > 0,
            ]);
        }
    }

    /**
     * Upload product images
     */
    public function uploadImages(Product $product, array $images): void
    {
        $disk = $this->getStorageDisk();
        $uploadedPaths = [];
        
        foreach ($images as $index => $image) {
            $path = Storage::disk($disk)->putFile(
                config('store.storage.paths.product_images') . '/' . $product->store->uuid . '/' . $product->uuid,
                $image
            );
            
            $uploadedPaths[] = $path;
            
            // First image is featured
            if ($index === 0) {
                $product->update(['featured_image' => $path]);
            }
        }
        
        $product->update(['images' => $uploadedPaths]);
    }

    /**
     * Upload digital file
     */
    public function uploadDigitalFile(Product $product, $file): void
    {
        $disk = $this->getStorageDisk();
        $path = Storage::disk($disk)->putFile(
            config('store.storage.paths.digital_products') . '/' . $product->store->uuid . '/' . $product->uuid,
            $file
        );
        
        $product->update(['digital_file_path' => $path]);
    }

    /**
     * Validate product creation
     */
    protected function validateProductCreation(Store $store): void
    {
        if (!$store->canAddProducts()) {
            throw new \Exception('Product limit reached. Upgrade your subscription to add more products.');
        }
    }

    /**
     * Generate unique slug
     */
    protected function generateUniqueSlug(string $name, int $storeId, ?int $excludeId = null): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;
        
        while (Product::where('store_id', $storeId)
            ->where('slug', $slug)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->exists()) {
            $slug = $originalSlug . '-' . $counter++;
        }
        
        return $slug;
    }
}
