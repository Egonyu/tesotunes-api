<?php

namespace App\Observers\Store;

use App\Modules\Store\Models\Product;
use App\Services\ActivityService;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

class ProductObserver
{
    public function created(Product $product): void
    {
        try {
            $store = $product->store;
            $user = $store?->user;

            if ($user && $product->status === 'active') {
                ActivityService::log(
                    actor: $user,
                    action: 'listed_product',
                    subject: $product,
                    metadata: [
                        'product_name' => $product->name,
                        'store_name' => $store->name ?? null,
                        'price_ugx' => $product->price_ugx ?? null,
                    ]
                );

                FeedItemService::create([
                    'type' => 'store_created',
                    'module' => 'store',
                    'title' => ($store->name ?? 'A store').' listed a new product: '.$product->name,
                    'body' => $product->description ? substr($product->description, 0, 200) : null,
                    'actor_id' => $user->id,
                    'actor_type' => 'user',
                    'actor_name' => $store->name ?? $user->name,
                    'actor_avatar_url' => $store->logo_url ?? $user->avatar_url ?? null,
                    'subject_type' => Product::class,
                    'subject_id' => $product->id,
                    'media_type' => 'image',
                    'media_url' => $product->image_url ?? null,
                    'actions' => [
                        ['type' => 'view', 'label' => 'View Product', 'url' => "/store/{$store->slug}/products/{$product->slug}"],
                    ],
                    'extras' => [
                        'price_ugx' => $product->price_ugx ?? null,
                        'price_credits' => $product->price_credits ?? null,
                    ],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('ProductObserver: Failed to create feed item', ['product_id' => $product->id, 'error' => $e->getMessage()]);
        }
    }

    public function updated(Product $product): void
    {
        // Create feed item when product becomes active for the first time
        if ($product->isDirty('status') && $product->status === 'active' && $product->getOriginal('status') !== 'active') {
            $this->created($product);
        }
    }
}
