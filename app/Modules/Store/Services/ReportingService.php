<?php

namespace App\Modules\Store\Services;

use App\Modules\Store\Models\{Store, Order};
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * Reporting Service
 * 
 * Generates CSV and PDF reports for store analytics
 */
class ReportingService
{
    protected AnalyticsService $analyticsService;

    public function __construct(AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Generate sales report CSV
     */
    public function generateSalesCSV(Store $store, string $period = '30days'): string
    {
        $dateRange = $this->analyticsService->getDateRange($period);
        
        $orders = Order::where('store_id', $store->id)
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->with(['user', 'items.product'])
            ->get();

        $filename = "sales_report_{$store->slug}_" . now()->format('Y-m-d_His') . ".csv";
        $path = "reports/{$store->id}/{$filename}";

        $csv = fopen(Storage::disk('local')->path($path), 'w');

        // Header
        fputcsv($csv, [
            'Order Number',
            'Date',
            'Customer',
            'Email',
            'Items',
            'Payment Method',
            'Total (UGX)',
            'Credits',
            'Status',
            'Payment Status',
        ]);

        // Data
        foreach ($orders as $order) {
            fputcsv($csv, [
                $order->order_number,
                $order->created_at->format('Y-m-d H:i'),
                $order->user->name,
                $order->user->email,
                $order->items->count(),
                ucfirst(str_replace('_', ' ', $order->payment_method)),
                $order->total_ugx,
                $order->total_credits,
                ucfirst($order->status),
                ucfirst($order->payment_status),
            ]);
        }

        fclose($csv);

        return $path;
    }

    /**
     * Generate products performance CSV
     */
    public function generateProductsCSV(Store $store, string $period = '30days'): string
    {
        $dateRange = $this->analyticsService->getDateRange($period);
        
        $products = $store->products()
            ->withCount(['orderItems' => function ($query) use ($dateRange) {
                $query->whereHas('order', function ($q) use ($dateRange) {
                    $q->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                      ->where('payment_status', 'paid');
                });
            }])
            ->with(['orderItems' => function ($query) use ($dateRange) {
                $query->whereHas('order', function ($q) use ($dateRange) {
                    $q->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                      ->where('payment_status', 'paid');
                });
            }])
            ->get();

        $filename = "products_report_{$store->slug}_" . now()->format('Y-m-d_His') . ".csv";
        $path = "reports/{$store->id}/{$filename}";

        $csv = fopen(Storage::disk('local')->path($path), 'w');

        // Header
        fputcsv($csv, [
            'Product Name',
            'SKU',
            'Price (UGX)',
            'Price (Credits)',
            'Inventory',
            'Units Sold',
            'Revenue (UGX)',
            'Views',
            'Conversion Rate (%)',
            'Average Rating',
            'Reviews',
            'Status',
        ]);

        // Data
        foreach ($products as $product) {
            $unitsSold = $product->orderItems->sum('quantity');
            $revenue = $product->orderItems->sum(fn($item) => $item->price_ugx * $item->quantity);
            $conversionRate = $product->views_count > 0 ? ($unitsSold / $product->views_count) * 100 : 0;

            fputcsv($csv, [
                $product->name,
                $product->sku ?? 'N/A',
                $product->price_ugx,
                $product->price_credits,
                $product->track_inventory ? $product->inventory_quantity : 'Unlimited',
                $unitsSold,
                $revenue,
                $product->views_count,
                round($conversionRate, 2),
                $product->average_rating ?? 0,
                $product->reviews_count ?? 0,
                ucfirst($product->status),
            ]);
        }

        fclose($csv);

        return $path;
    }

    /**
     * Generate customer report CSV
     */
    public function generateCustomersCSV(Store $store, string $period = '30days'): string
    {
        $dateRange = $this->analyticsService->getDateRange($period);
        
        $customers = Order::where('store_id', $store->id)
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->where('payment_status', 'paid')
            ->with('user')
            ->get()
            ->groupBy('user_id')
            ->map(function ($orders) {
                $user = $orders->first()->user;
                return [
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone ?? 'N/A',
                    'orders_count' => $orders->count(),
                    'total_spent' => $orders->sum('total_ugx'),
                    'avg_order_value' => $orders->avg('total_ugx'),
                    'first_order' => $orders->min('created_at'),
                    'last_order' => $orders->max('created_at'),
                ];
            });

        $filename = "customers_report_{$store->slug}_" . now()->format('Y-m-d_His') . ".csv";
        $path = "reports/{$store->id}/{$filename}";

        $csv = fopen(Storage::disk('local')->path($path), 'w');

        // Header
        fputcsv($csv, [
            'Customer Name',
            'Email',
            'Phone',
            'Total Orders',
            'Total Spent (UGX)',
            'Average Order Value',
            'First Order',
            'Last Order',
        ]);

        // Data
        foreach ($customers as $customer) {
            fputcsv($csv, [
                $customer['name'],
                $customer['email'],
                $customer['phone'],
                $customer['orders_count'],
                $customer['total_spent'],
                round($customer['avg_order_value'], 2),
                Carbon::parse($customer['first_order'])->format('Y-m-d'),
                Carbon::parse($customer['last_order'])->format('Y-m-d'),
            ]);
        }

        fclose($csv);

        return $path;
    }

    /**
     * Generate comprehensive PDF report
     * Returns HTML that can be printed/saved as PDF via browser
     */
    public function generatePDFReport(Store $store, string $period = '30days'): string
    {
        $data = $this->analyticsService->exportData($store, $period);
        
        // Generate HTML report that can be printed as PDF
        $html = view('store::reports.pdf', [
            'store' => $store,
            'data' => $data,
            'period' => $period,
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ])->render();

        $filename = "report_{$store->slug}_" . now()->format('Y-m-d_His') . ".html";
        $path = "reports/{$store->id}/{$filename}";

        // Ensure directory exists
        Storage::disk('local')->makeDirectory("reports/{$store->id}");
        Storage::disk('local')->put($path, $html);

        return $path;
    }

    /**
     * Generate inventory report CSV
     */
    public function generateInventoryCSV(Store $store): string
    {
        $products = $store->products()
            ->where('track_inventory', true)
            ->orderBy('inventory_quantity')
            ->get();

        $filename = "inventory_report_{$store->slug}_" . now()->format('Y-m-d_His') . ".csv";
        $path = "reports/{$store->id}/{$filename}";

        $csv = fopen(Storage::disk('local')->path($path), 'w');

        // Header
        fputcsv($csv, [
            'Product Name',
            'SKU',
            'Current Stock',
            'Status',
            'Price (UGX)',
            'Stock Value (UGX)',
            'Reorder Needed',
        ]);

        // Data
        foreach ($products as $product) {
            $stockValue = $product->inventory_quantity * $product->price_ugx;
            $reorderNeeded = $product->inventory_quantity <= 5 ? 'Yes' : 'No';
            $status = $product->inventory_quantity == 0 ? 'Out of Stock' : 
                     ($product->inventory_quantity <= 5 ? 'Low Stock' : 'In Stock');

            fputcsv($csv, [
                $product->name,
                $product->sku ?? 'N/A',
                $product->inventory_quantity,
                $status,
                $product->price_ugx,
                $stockValue,
                $reorderNeeded,
            ]);
        }

        fclose($csv);

        return $path;
    }

    /**
     * Schedule automatic reports (can be run via cron)
     */
    public function scheduleMonthlyReport(Store $store): void
    {
        // Generate reports at end of month
        $this->generateSalesCSV($store, 'month');
        $this->generateProductsCSV($store, 'month');
        $this->generateCustomersCSV($store, 'month');

        // TODO: Email reports to store owner
        // Mail::to($store->owner->email)->send(new MonthlyReportMail($reports));
    }

    /**
     * Get available report types
     */
    public function getAvailableReports(): array
    {
        return [
            'sales' => [
                'name' => 'Sales Report',
                'description' => 'Detailed sales and order information',
                'formats' => ['csv', 'pdf'],
            ],
            'products' => [
                'name' => 'Products Performance',
                'description' => 'Product sales, views, and conversion metrics',
                'formats' => ['csv'],
            ],
            'customers' => [
                'name' => 'Customer Report',
                'description' => 'Customer purchase behavior and lifetime value',
                'formats' => ['csv'],
            ],
            'inventory' => [
                'name' => 'Inventory Report',
                'description' => 'Current stock levels and reorder needs',
                'formats' => ['csv'],
            ],
            'comprehensive' => [
                'name' => 'Comprehensive Report',
                'description' => 'Complete analytics with charts and graphs',
                'formats' => ['pdf'],
            ],
        ];
    }

    /**
     * Cleanup old reports (run via scheduler)
     */
    public function cleanupOldReports(int $days = 90): int
    {
        $deleted = 0;
        $cutoffDate = now()->subDays($days);

        $files = Storage::disk('local')->allFiles('reports');
        
        foreach ($files as $file) {
            if (Storage::disk('local')->lastModified($file) < $cutoffDate->timestamp) {
                Storage::disk('local')->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
