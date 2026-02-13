<?php

namespace App\Modules\Store\Services;

use App\Modules\Store\Models\{Store, Product, Order};
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Analytics Service
 * 
 * Provides analytics and metrics for stores
 * Optimized for dashboard displays with charts
 */
class AnalyticsService
{
    /**
     * Get store dashboard analytics
     */
    public function getStoreDashboard(Store $store, string $period = '30days'): array
    {
        $dateRange = $this->getDateRange($period);

        return [
            'overview' => $this->getOverviewMetrics($store, $dateRange),
            'sales' => $this->getSalesMetrics($store, $dateRange),
            'products' => $this->getProductMetrics($store, $dateRange),
            'customers' => $this->getCustomerMetrics($store, $dateRange),
            'charts' => [
                'sales_trend' => $this->getSalesTrend($store, $dateRange),
                'order_status' => $this->getOrderStatusBreakdown($store, $dateRange),
                'top_products' => $this->getTopProducts($store, $dateRange, 10),
                'revenue_by_payment' => $this->getRevenueByPaymentMethod($store, $dateRange),
            ],
        ];
    }

    /**
     * Get overview metrics (KPIs)
     */
    protected function getOverviewMetrics(Store $store, array $dateRange): array
    {
        $currentPeriod = Order::where('store_id', $store->id)
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->where('payment_status', 'paid');

        $previousPeriod = Order::where('store_id', $store->id)
            ->whereBetween('created_at', [$dateRange['previous_start'], $dateRange['previous_end']])
            ->where('payment_status', 'paid');

        $currentRevenue = $currentPeriod->sum('total_ugx');
        $previousRevenue = $previousPeriod->sum('total_ugx');
        $revenueChange = $previousRevenue > 0 
            ? (($currentRevenue - $previousRevenue) / $previousRevenue) * 100 
            : 100;

        $currentOrders = $currentPeriod->count();
        $previousOrders = $previousPeriod->count();
        $ordersChange = $previousOrders > 0 
            ? (($currentOrders - $previousOrders) / $previousOrders) * 100 
            : 100;

        $avgOrderValue = $currentOrders > 0 ? $currentRevenue / $currentOrders : 0;

        return [
            'total_revenue' => [
                'value' => $currentRevenue,
                'formatted' => 'UGX ' . number_format($currentRevenue),
                'change' => round($revenueChange, 1),
                'trend' => $revenueChange >= 0 ? 'up' : 'down',
            ],
            'total_orders' => [
                'value' => $currentOrders,
                'change' => round($ordersChange, 1),
                'trend' => $ordersChange >= 0 ? 'up' : 'down',
            ],
            'average_order_value' => [
                'value' => $avgOrderValue,
                'formatted' => 'UGX ' . number_format($avgOrderValue),
            ],
            'conversion_rate' => [
                'value' => $this->calculateConversionRate($store, $dateRange),
                'formatted' => number_format($this->calculateConversionRate($store, $dateRange), 1) . '%',
            ],
        ];
    }

    /**
     * Get sales metrics
     */
    protected function getSalesMetrics(Store $store, array $dateRange): array
    {
        $orders = Order::where('store_id', $store->id)
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->where('payment_status', 'paid')
            ->get();

        return [
            'total_sales' => $orders->sum('total_ugx'),
            'ugx_sales' => $orders->where('payment_method', 'mobile_money')->sum('total_ugx'),
            'credit_sales' => $orders->sum('total_credits'),
            'pending_orders' => Order::where('store_id', $store->id)
                ->where('status', 'pending')
                ->count(),
            'processing_orders' => Order::where('store_id', $store->id)
                ->where('status', 'processing')
                ->count(),
            'completed_orders' => Order::where('store_id', $store->id)
                ->where('status', 'delivered')
                ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->count(),
        ];
    }

    /**
     * Get product metrics
     */
    protected function getProductMetrics(Store $store, array $dateRange): array
    {
        return [
            'total_products' => $store->products()->count(),
            'active_products' => $store->activeProducts()->count(),
            'low_stock_products' => $store->products()
                ->where('track_inventory', true)
                ->where('inventory_quantity', '<=', 5)
                ->count(),
            'out_of_stock' => $store->products()
                ->where('track_inventory', true)
                ->where('inventory_quantity', 0)
                ->count(),
            'total_views' => $store->products()
                ->whereBetween('updated_at', [$dateRange['start'], $dateRange['end']])
                ->sum('views_count'),
        ];
    }

    /**
     * Get customer metrics
     */
    protected function getCustomerMetrics(Store $store, array $dateRange): array
    {
        $uniqueCustomers = Order::where('store_id', $store->id)
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->where('payment_status', 'paid')
            ->distinct('user_id')
            ->count('user_id');

        $repeatCustomers = Order::where('store_id', $store->id)
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->where('payment_status', 'paid')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        return [
            'unique_customers' => $uniqueCustomers,
            'repeat_customers' => $repeatCustomers,
            'repeat_rate' => $uniqueCustomers > 0 
                ? round(($repeatCustomers / $uniqueCustomers) * 100, 1) 
                : 0,
            'new_customers' => $uniqueCustomers - $repeatCustomers,
        ];
    }

    /**
     * Get sales trend (for charts)
     */
    protected function getSalesTrend(Store $store, array $dateRange): array
    {
        $data = DB::table('orders')
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(total_ugx) as revenue')
            )
            ->where('store_id', $store->id)
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'labels' => $data->pluck('date')->map(fn($d) => Carbon::parse($d)->format('M d'))->toArray(),
            'datasets' => [
                [
                    'label' => 'Revenue (UGX)',
                    'data' => $data->pluck('revenue')->toArray(),
                    'type' => 'line',
                    'color' => '#3B82F6',
                ],
                [
                    'label' => 'Orders',
                    'data' => $data->pluck('orders')->toArray(),
                    'type' => 'bar',
                    'color' => '#10B981',
                ],
            ],
        ];
    }

    /**
     * Get order status breakdown (for pie chart)
     */
    protected function getOrderStatusBreakdown(Store $store, array $dateRange): array
    {
        $data = DB::table('orders')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->where('store_id', $store->id)
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->groupBy('status')
            ->get();

        return [
            'labels' => $data->pluck('status')->map(fn($s) => ucfirst($s))->toArray(),
            'data' => $data->pluck('count')->toArray(),
            'colors' => ['#F59E0B', '#3B82F6', '#10B981', '#6366F1', '#EF4444'],
        ];
    }

    /**
     * Get top selling products
     */
    protected function getTopProducts(Store $store, array $dateRange, int $limit = 10): array
    {
        $products = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->select(
                'products.id',
                'products.name',
                DB::raw('SUM(order_items.quantity) as total_sold'),
                DB::raw('SUM(order_items.price_ugx * order_items.quantity) as revenue')
            )
            ->where('orders.store_id', $store->id)
            ->where('orders.payment_status', 'paid')
            ->whereBetween('orders.created_at', [$dateRange['start'], $dateRange['end']])
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_sold')
            ->limit($limit)
            ->get();

        return [
            'labels' => $products->pluck('name')->toArray(),
            'data' => $products->pluck('total_sold')->toArray(),
            'revenue' => $products->pluck('revenue')->toArray(),
        ];
    }

    /**
     * Get revenue by payment method (for pie chart)
     */
    protected function getRevenueByPaymentMethod(Store $store, array $dateRange): array
    {
        $data = DB::table('orders')
            ->select(
                'payment_method',
                DB::raw('SUM(total_ugx) as revenue'),
                DB::raw('COUNT(*) as count')
            )
            ->where('store_id', $store->id)
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->groupBy('payment_method')
            ->get();

        return [
            'labels' => $data->pluck('payment_method')->map(fn($m) => ucfirst(str_replace('_', ' ', $m)))->toArray(),
            'data' => $data->pluck('revenue')->toArray(),
            'counts' => $data->pluck('count')->toArray(),
            'colors' => ['#3B82F6', '#10B981', '#F59E0B', '#EF4444'],
        ];
    }

    /**
     * Calculate conversion rate
     */
    protected function calculateConversionRate(Store $store, array $dateRange): float
    {
        // Total views vs purchases
        $views = $store->products()->whereBetween('updated_at', [$dateRange['start'], $dateRange['end']])->sum('views_count');
        $orders = Order::where('store_id', $store->id)
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->where('payment_status', 'paid')
            ->count();

        return $views > 0 ? ($orders / $views) * 100 : 0;
    }

    /**
     * Get date range based on period
     */
    protected function getDateRange(string $period): array
    {
        $end = Carbon::now();
        
        $start = match($period) {
            '7days' => Carbon::now()->subDays(7),
            '30days' => Carbon::now()->subDays(30),
            '90days' => Carbon::now()->subDays(90),
            'year' => Carbon::now()->subYear(),
            'month' => Carbon::now()->startOfMonth(),
            'week' => Carbon::now()->startOfWeek(),
            default => Carbon::now()->subDays(30),
        };

        $duration = $end->diffInDays($start);
        $previousStart = $start->copy()->subDays($duration);
        $previousEnd = $start->copy();

        return [
            'start' => $start,
            'end' => $end,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
        ];
    }

    /**
     * Get real-time metrics (cached for 5 minutes)
     */
    public function getRealTimeMetrics(Store $store): array
    {
        return cache()->remember("store:{$store->id}:realtime", 300, function () use ($store) {
            return [
                'online_views' => 0, // TODO: Implement real-time tracking
                'active_carts' => DB::table('shopping_carts')
                    ->whereHas('items', fn($q) => $q->whereHas('product', fn($q2) => $q2->where('store_id', $store->id)))
                    ->where('updated_at', '>=', now()->subHours(1))
                    ->count(),
                'pending_orders' => Order::where('store_id', $store->id)
                    ->where('status', 'pending')
                    ->count(),
                'today_revenue' => Order::where('store_id', $store->id)
                    ->where('payment_status', 'paid')
                    ->whereDate('created_at', today())
                    ->sum('total_ugx'),
            ];
        });
    }

    /**
     * Export analytics data to array (for CSV/PDF)
     */
    public function exportData(Store $store, string $period = '30days'): array
    {
        $dateRange = $this->getDateRange($period);
        
        return [
            'store' => [
                'name' => $store->name,
                'owner' => $store->owner->name,
                'generated_at' => now()->toDateTimeString(),
                'period' => $period,
            ],
            'metrics' => $this->getStoreDashboard($store, $period),
        ];
    }
}
