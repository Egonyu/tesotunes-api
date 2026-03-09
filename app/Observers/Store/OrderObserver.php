<?php

namespace App\Observers\Store;

use App\Modules\Store\Models\Order;
use App\Services\ActivityService;
use App\Services\FeedItemService;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    public function updated(Order $order): void
    {
        // Create feed item when order is completed/paid
        if ($order->isDirty('status') && in_array($order->status, ['paid', 'completed'])) {
            try {
                $buyer = $order->buyer ?? $order->user;
                if (! $buyer) {
                    return;
                }

                ActivityService::log(
                    actor: $buyer,
                    action: 'purchased_product',
                    subject: $order,
                    metadata: [
                        'store_name' => $order->store->name ?? null,
                        'total_ugx'  => $order->total_ugx,
                        'item_count' => $order->items()->count(),
                    ]
                );

                // Feed item for purchases — visibility is 'members' (private by default)
                FeedItemService::create([
                    'type'          => 'product_purchased',
                    'module'        => 'store',
                    'title'         => ($buyer->name ?? 'Someone') . ' made a purchase from ' . ($order->store->name ?? 'a store'),
                    'actor_id'      => $buyer->id,
                    'actor_type'    => 'user',
                    'actor_name'    => $buyer->name,
                    'actor_avatar_url' => $buyer->avatar_url ?? null,
                    'subject_type'  => Order::class,
                    'subject_id'    => $order->id,
                    'visibility'    => 'members',
                    'extras'        => [
                        'store_name' => $order->store->name ?? null,
                        'item_count' => $order->items()->count(),
                    ],
                ]);
            } catch (\Exception $e) {
                Log::error('OrderObserver: Failed to create feed item', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
