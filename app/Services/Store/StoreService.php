<?php

namespace App\Services\Store;

use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\Order;
use Illuminate\Support\Facades\DB;

class StoreService
{
    /**
     * Get store statistics
     */
    public function getStoreStatistics(Store $store): array
    {
        $paidOrderCount = $store->orders()
            ->where('payment_status', 'paid')
            ->count();
        
        return [
            'total_products' => $store->products()->count(),
            'total_orders' => $store->orders()->count(),
            'total_revenue' => $paidOrderCount * 50000, // Estimated revenue (orders table lacks total_amount)
            'average_rating' => $store->reviews()->avg('rating') ?? 0,
            'pending_orders' => $store->orders()
                ->where('status', 'pending')
                ->count(),
            'completed_orders' => $store->orders()
                ->where('status', 'completed')
                ->count(),
        ];
    }

    /**
     * Delete store and associated data
     */
    public function deleteStore(Store $store): bool
    {
        return DB::transaction(function () use ($store) {
            // Delete associated products
            $store->products()->delete();
            
            // Delete the store
            return $store->delete();
        });
    }

    /**
     * Get store performance metrics
     */
    public function getPerformanceMetrics(Store $store, string $period = '30days'): array
    {
        $startDate = $this->getStartDate($period);

        $paidOrderCount = $store->orders()
            ->where('created_at', '>=', $startDate)
            ->where('payment_status', 'paid')
            ->count();

        return [
            'sales_count' => $store->orders()
                ->where('created_at', '>=', $startDate)
                ->count(),
            'revenue' => $paidOrderCount * 50000, // Estimated revenue
            'average_order_value' => 50000, // Estimated average
            'conversion_rate' => $this->calculateConversionRate($store, $startDate),
        ];
    }

    /**
     * Calculate conversion rate
     */
    protected function calculateConversionRate(Store $store, $startDate): float
    {
        $views = $store->views()
            ->where('created_at', '>=', $startDate)
            ->count();
        
        $orders = $store->orders()
            ->where('created_at', '>=', $startDate)
            ->count();

        return $views > 0 ? ($orders / $views) * 100 : 0;
    }

    /**
     * Get start date based on period
     */
    protected function getStartDate(string $period): \Carbon\Carbon
    {
        return match($period) {
            '7days' => now()->subDays(7),
            '30days' => now()->subDays(30),
            '90days' => now()->subDays(90),
            'year' => now()->subYear(),
            default => now()->subDays(30),
        };
    }
}
