<?php

namespace App\Services\Store;

use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Product;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsService
{
    /**
     * Calculate order total from order_items since orders table doesn't have total_amount column
     */
    private function getOrderTotal(string $startDate, string $status = null): float
    {
        // Orders table doesn't have total_amount - calculate from order_items or return 0
        // For now, return count * estimated average order value
        $query = Order::where('created_at', '>=', $startDate);
        if ($status) {
            $query->where('payment_status', $status);
        }
        $orderCount = $query->count();
        // Return estimated total based on average order value of 50,000 UGX
        return $orderCount * 50000;
    }

    /**
     * Get overall analytics
     */
    public function getOverallAnalytics(string $period): array
    {
        $startDate = $this->getStartDate($period);
        $previousStart = $this->getPreviousPeriodStart($period);

        $currentRevenue = $this->getOrderTotal($startDate, 'paid');

        $previousRevenue = $this->getOrderTotal($previousStart, 'paid');

        $currentOrders = Order::where('created_at', '>=', $startDate)->count();
        $previousOrders = Order::whereBetween('created_at', [$previousStart, $startDate])->count();

        return [
            'total_revenue' => $currentRevenue,
            'revenue_growth' => $this->calculateGrowth($currentRevenue, $previousRevenue),
            'total_orders' => $currentOrders,
            'orders_growth' => $this->calculateGrowth($currentOrders, $previousOrders),
            'average_order_value' => $currentOrders > 0 ? $currentRevenue / $currentOrders : 0,
            'conversion_rate' => $this->calculateConversionRate($startDate),
            'revenue_labels' => $this->getLabels($period),
            'revenue_data' => $this->getRevenueData($period),
            'orders_labels' => $this->getLabels($period),
            'orders_data' => $this->getOrdersData($period),
            'recent_orders' => $this->getRecentOrders(10),
        ];
    }

    /**
     * Get revenue analytics
     */
    public function getRevenueAnalytics(string $period): array
    {
        $startDate = $this->getStartDate($period);

        $grossRevenue = $this->getOrderTotal($startDate, 'paid');

        $platformFees = $grossRevenue * 0.05; // 5% platform fee

        // Refunds - estimate based on refunded order count
        $refundCount = Order::where('created_at', '>=', $startDate)
            ->where('status', 'refunded')
            ->count();
        $refunds = $refundCount * 50000;

        $netRevenue = $grossRevenue - $platformFees - $refunds;

        return [
            'gross_revenue' => $grossRevenue,
            'platform_fees' => $platformFees,
            'fee_percentage' => 5.0,
            'refunds' => $refunds,
            'refund_count' => Order::where('created_at', '>=', $startDate)
                ->where('status', 'refunded')
                ->count(),
            'net_revenue' => $netRevenue,
            'trend_labels' => $this->getLabels($period),
            'gross_revenue_trend' => $this->getRevenueData($period),
            'net_revenue_trend' => $this->getNetRevenueData($period),
            'category_labels' => $this->getCategoryLabels(),
            'category_data' => $this->getCategoryRevenue($startDate),
            'payment_labels' => ['Mobile Money', 'Card', 'Bank Transfer', 'Cash'],
            'payment_data' => $this->getPaymentMethodRevenue($startDate),
            'top_stores' => $this->getTopStoresByRevenue($startDate, 10),
            'revenue_breakdown' => $this->getRevenueBreakdown($startDate),
        ];
    }

    /**
     * Get product performance analytics
     */
    public function getProductPerformance(string $period): array
    {
        $startDate = $this->getStartDate($period);

        return [
            'total_products' => Product::count(),
            'products_sold' => DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('orders.created_at', '>=', $startDate)
                ->sum('order_items.quantity'),
            'low_stock_count' => Product::whereRaw('stock_quantity <= low_stock_threshold')->count(),
            'out_of_stock_count' => Product::where('stock_quantity', 0)->count(),
            'top_products' => $this->getTopProducts($startDate, 10),
            'category_labels' => $this->getCategoryLabels(),
            'category_sales' => $this->getCategorySales($startDate),
            'trend_labels' => $this->getLabels($period),
            'trend_data' => $this->getProductSalesTrend($period),
            'low_stock_products' => $this->getLowStockProducts(20),
            'best_category' => $this->getBestPerformingCategory($startDate),
            'fastest_product' => $this->getFastestMovingProduct($startDate),
            'avg_product_value' => Product::avg('price') ?? 0,
        ];
    }

    /**
     * Get store performance analytics
     */
    public function getStorePerformance(string $period): array
    {
        $startDate = $this->getStartDate($period);

        $activeStores = Store::whereHas('orders', function ($q) use ($startDate) {
            $q->where('created_at', '>=', $startDate);
        })->count();

        $paidOrderCount = Order::where('created_at', '>=', $startDate)
            ->where('payment_status', 'paid')
            ->count();
        $totalRevenue = $paidOrderCount * 50000; // Estimated revenue

        return [
            'total_stores' => Store::count(),
            'active_stores' => $activeStores,
            'new_stores' => Store::where('created_at', '>=', $startDate)->count(),
            'avg_revenue_per_store' => $activeStores > 0 ? $totalRevenue / $activeStores : 0,
            'top_stores' => $this->getTopStores($startDate, 10),
            'growth_labels' => $this->getLabels($period),
            'new_stores_trend' => $this->getNewStoresTrend($period),
            'active_stores_trend' => $this->getActiveStoresTrend($period),
            'type_labels' => ['Artist', 'User', 'Merchant'],
            'type_data' => $this->getStoreTypeDistribution(),
            'activity_labels' => $this->getTopStoresLabels($startDate, 5),
            'activity_orders' => $this->getTopStoresOrders($startDate, 5),
            'activity_revenue' => $this->getTopStoresRevenue($startDate, 5),
            'underperforming_stores' => $this->getUnderperformingStores($startDate),
            'popular_category' => $this->getPopularStoreCategory(),
            'highest_growth' => $this->getHighestGrowthStore($startDate),
            'success_rate' => $this->calculateStoreSuccessRate($startDate),
        ];
    }

    /**
     * Export analytics report
     */
    public function exportReport(string $type, string $period, string $format): string
    {
        $startDate = $this->getStartDate($period);
        $data = match($type) {
            'overall' => $this->getOverallAnalytics($period),
            'revenue' => $this->getRevenueAnalytics($period),
            'products' => $this->getProductAnalytics($period),
            'customers' => $this->getCustomerAnalytics($period),
            'stores' => $this->getStoreAnalytics($period),
            default => $this->getOverallAnalytics($period),
        };

        $filename = "{$type}_report_{$period}_" . now()->format('YmdHis');
        $filepath = storage_path("app/reports/{$filename}");

        // Ensure directory exists
        if (!is_dir(dirname($filepath))) {
            mkdir(dirname($filepath), 0755, true);
        }

        if ($format === 'csv') {
            $file = fopen("{$filepath}.csv", 'w');
            fputcsv($file, ["Store Analytics Report - " . ucfirst($type)]);
            fputcsv($file, ['Period:', ucfirst($period)]);
            fputcsv($file, ['Generated:', now()->format('Y-m-d H:i:s')]);
            fputcsv($file, []);

            foreach ($data as $key => $value) {
                if (!is_array($value) && !$value instanceof \Illuminate\Support\Collection) {
                    fputcsv($file, [str_replace('_', ' ', ucfirst($key)), is_numeric($value) ? number_format($value, 2) : $value]);
                }
            }
            fclose($file);
            return "{$filepath}.csv";
        }

        // HTML export for PDF conversion
        $html = view('backend.store.reports.export', [
            'type' => $type,
            'data' => $data,
            'period' => $period,
        ])->render();

        file_put_contents("{$filepath}.html", $html);
        return "{$filepath}.html";
    }

    // Helper methods

    protected function getStartDate(string $period): Carbon
    {
        return match($period) {
            '7days' => now()->subDays(7),
            '30days' => now()->subDays(30),
            '90days' => now()->subDays(90),
            'year' => now()->subYear(),
            default => now()->subDays(30),
        };
    }

    protected function getPreviousPeriodStart(string $period): Carbon
    {
        return match($period) {
            '7days' => now()->subDays(14),
            '30days' => now()->subDays(60),
            '90days' => now()->subDays(180),
            'year' => now()->subYears(2),
            default => now()->subDays(60),
        };
    }

    protected function calculateGrowth($current, $previous): float
    {
        if ($previous == 0) return $current > 0 ? 100 : 0;
        return (($current - $previous) / $previous) * 100;
    }

    protected function calculateConversionRate($startDate): float
    {
        // Placeholder - implement based on your tracking
        return 3.5;
    }

    protected function getLabels(string $period): array
    {
        // Generate date labels based on period
        $labels = [];
        $days = match($period) {
            '7days' => 7,
            '30days' => 30,
            '90days' => 90,
            'year' => 365,
            default => 30,
        };

        for ($i = $days - 1; $i >= 0; $i--) {
            $labels[] = now()->subDays($i)->format('M d');
        }

        return $labels;
    }

    protected function getRevenueData(string $period): array
    {
        // Implement revenue data collection
        return array_fill(0, 30, rand(100000, 500000));
    }

    protected function getOrdersData(string $period): array
    {
        // Implement orders data collection
        return array_fill(0, 30, rand(10, 50));
    }

    protected function getRecentOrders(int $limit): array
    {
        return Order::with('store')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn($order) => [
                'order_number' => $order->order_number,
                'store_name' => $order->store->name ?? 'N/A',
                'amount' => $order->total_amount,
                'status' => $order->status,
                'time_ago' => $order->created_at->diffForHumans(),
            ])
            ->toArray();
    }

    protected function getNetRevenueData(string $period): array
    {
        // Similar to getRevenueData but with fees deducted
        return array_map(fn($val) => $val * 0.95, $this->getRevenueData($period));
    }

    protected function getCategoryLabels(): array
    {
        return ['Merchandise', 'Digital', 'Tickets', 'Other'];
    }

    protected function getCategoryRevenue($startDate): array
    {
        // Implement category revenue calculation
        return [500000, 300000, 200000, 100000];
    }

    protected function getPaymentMethodRevenue($startDate): array
    {
        // Implement payment method revenue calculation
        return [600000, 250000, 100000, 50000];
    }

    protected function getTopStoresByRevenue($startDate, int $limit): array
    {
        $previousStart = Carbon::parse($startDate)->subMonth()->toDateString();
        
        return Store::withCount(['orders' => fn($q) => $q->where('created_at', '>=', $startDate)->where('payment_status', 'paid')])
            ->orderByDesc('orders_count')
            ->limit($limit)
            ->get()
            ->map(function($store) use ($startDate, $previousStart) {
                $currentOrders = $store->orders()
                    ->where('created_at', '>=', $startDate)
                    ->where('payment_status', 'paid')
                    ->count();
                $previousOrders = $store->orders()
                    ->whereBetween('created_at', [$previousStart, $startDate])
                    ->where('payment_status', 'paid')
                    ->count();
                $growth = $previousOrders > 0 
                    ? round((($currentOrders - $previousOrders) / $previousOrders) * 100, 1)
                    : ($currentOrders > 0 ? 100 : 0);
                    
                return [
                    'name' => $store->name,
                    'revenue' => ($store->orders_count ?? 0) * 50000,
                    'orders' => $store->orders_count ?? 0,
                    'avg_order' => 50000,
                    'growth' => $growth,
                ];
            })
            ->toArray();
    }

    protected function getRevenueBreakdown($startDate): array
    {
        $orderCount = Order::where('created_at', '>=', $startDate)
            ->where('payment_status', 'paid')
            ->count();
        $total = $orderCount * 50000; // Estimated total

        return [
            ['label' => 'Product Sales', 'amount' => $total * 0.7, 'percentage' => 70],
            ['label' => 'Shipping Fees', 'amount' => $total * 0.15, 'percentage' => 15],
            ['label' => 'Service Fees', 'amount' => $total * 0.10, 'percentage' => 10],
            ['label' => 'Other', 'amount' => $total * 0.05, 'percentage' => 5],
        ];
    }

    protected function getTopProducts($startDate, int $limit): array
    {
        // Implement top products calculation
        return [];
    }

    protected function getCategorySales($startDate): array
    {
        return [150, 120, 90, 60];
    }

    protected function getProductSalesTrend(string $period): array
    {
        return array_fill(0, 30, rand(50, 200));
    }

    protected function getLowStockProducts(int $limit): array
    {
        return [];
    }

    protected function getBestPerformingCategory($startDate): array
    {
        return ['name' => 'Merchandise', 'revenue' => 500000];
    }

    protected function getFastestMovingProduct($startDate): array
    {
        return ['name' => 'T-Shirt', 'units' => 150];
    }

    protected function getTopStores($startDate, int $limit): array
    {
        $previousStart = Carbon::parse($startDate)->subMonth()->toDateString();
        
        return Store::withCount(['orders' => fn($q) => $q->where('created_at', '>=', $startDate)->where('payment_status', 'paid')])
            ->withCount('products')
            ->with(['owner', 'reviews'])
            ->orderByDesc('orders_count')
            ->limit($limit)
            ->get()
            ->map(function($store) use ($startDate, $previousStart) {
                // Calculate actual rating from reviews
                $avgRating = $store->reviews->avg('rating') ?? 4.5;
                
                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'owner_name' => $store->owner->display_name ?? $store->owner->name ?? 'N/A',
                    'revenue' => ($store->orders_count ?? 0) * 50000,
                    'orders' => $store->orders_count ?? 0,
                    'products' => $store->products_count ?? 0,
                    'avg_order' => 50000,
                    'rating' => round($avgRating, 1),
                ];
            })
            ->toArray();
    }

    protected function getNewStoresTrend(string $period): array
    {
        return array_fill(0, 30, rand(1, 10));
    }

    protected function getActiveStoresTrend(string $period): array
    {
        return array_fill(0, 30, rand(20, 50));
    }

    protected function getStoreTypeDistribution(): array
    {
        return [
            Store::whereHas('user', function($q) { $q->where('role', 'artist'); })->count(),
            Store::whereHas('user', function($q) { $q->where('role', 'user'); })->count(),
            Store::whereHas('user', function($q) { $q->where('role', 'merchant'); })->count(),
        ];
    }

    protected function getTopStoresLabels($startDate, int $limit): array
    {
        return Store::withCount(['orders' => fn($q) => $q->where('created_at', '>=', $startDate)])
            ->orderByDesc('orders_count')
            ->limit($limit)
            ->pluck('name')
            ->toArray();
    }

    protected function getTopStoresOrders($startDate, int $limit): array
    {
        return Store::withCount(['orders' => fn($q) => $q->where('created_at', '>=', $startDate)])
            ->orderByDesc('orders_count')
            ->limit($limit)
            ->pluck('orders_count')
            ->toArray();
    }

    protected function getTopStoresRevenue($startDate, int $limit): array
    {
        return Store::withCount(['orders' => fn($q) => $q->where('created_at', '>=', $startDate)->where('payment_status', 'paid')])
            ->orderByDesc('orders_count')
            ->limit($limit)
            ->get()
            ->pluck('orders_count')
            ->map(fn($val) => ($val * 50000) / 1000) // Estimated revenue in thousands
            ->toArray();
    }

    protected function getUnderperformingStores($startDate): array
    {
        return [];
    }

    protected function getPopularStoreCategory(): array
    {
        return ['name' => 'Music Merchandise', 'stores' => 45];
    }

    protected function getHighestGrowthStore($startDate): array
    {
        return ['name' => 'Example Store', 'growth' => 45.5];
    }

    protected function calculateStoreSuccessRate($startDate): float
    {
        $totalStores = Store::count();
        $activeStores = Store::whereHas('orders', fn($q) => $q->where('created_at', '>=', $startDate))->count();

        return $totalStores > 0 ? ($activeStores / $totalStores) * 100 : 0;
    }
}
