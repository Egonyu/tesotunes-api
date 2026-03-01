<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\Loyalty\LoyaltyPointsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class AwardStoreLoyaltyPoints implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        protected LoyaltyPointsService $pointsService,
    ) {}

    /**
     * Award loyalty points when a store order is paid.
     */
    public function handleOrderPaid(OrderPaid $event): void
    {
        try {
            $order = $event->order;

            if (! $order || ! $order->user_id) {
                return;
            }

            $user = \App\Models\User::find($order->user_id);
            if (! $user) {
                return;
            }

            $total = $order->total ?? $order->total_amount ?? 0;
            $basePoints = config('loyalty.points_earning.purchase_per_100_ugx', 1);
            $points = (int) floor($total / 100) * $basePoints;

            if ($points > 0) {
                $this->pointsService->awardPoints(
                    $user,
                    $points,
                    'purchase',
                    $order->id,
                    get_class($order),
                    "Store order #{$order->id}"
                );
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to award loyalty points for store order: {$e->getMessage()}");
        }
    }
}
