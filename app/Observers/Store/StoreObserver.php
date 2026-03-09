<?php

namespace App\Observers\Store;

use App\Modules\Store\Models\Store;
use App\Services\ActivityService;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

class StoreObserver
{
    public function created(Store $store): void
    {
        try {
            if ($store->user_id) {
                ActivityService::log(
                    actor: $store->user,
                    action: 'created_store',
                    subject: $store,
                    metadata: [
                        'store_name' => $store->name,
                    ]
                );

                FeedItemService::create([
                    'type'          => 'store_created',
                    'module'        => 'store',
                    'title'         => ($store->user->name ?? 'Someone') . ' opened a new store: ' . $store->name,
                    'body'          => $store->description ? substr($store->description, 0, 200) : null,
                    'actor_id'      => $store->user_id,
                    'actor_type'    => 'user',
                    'actor_name'    => $store->user->name ?? null,
                    'actor_avatar_url' => $store->user->avatar_url ?? null,
                    'subject_type'  => Store::class,
                    'subject_id'    => $store->id,
                    'media_type'    => 'image',
                    'media_url'     => $store->logo_url ?? null,
                    'actions'       => [
                        ['type' => 'view', 'label' => 'Visit Store', 'url' => "/store/{$store->slug}"],
                    ],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('StoreObserver: Failed to create feed item', ['store_id' => $store->id, 'error' => $e->getMessage()]);
        }
    }
}
