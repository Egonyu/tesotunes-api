<?php

namespace App\Modules\Store\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Report Generation API Controller
 */
class ReportController extends Controller
{
    public function __construct(
        protected ReportingService $reportingService
    ) {}

    /**
     * Get available report types
     */
    public function index(): JsonResponse
    {
        $reports = $this->reportingService->getAvailableReports();

        return response()->json([
            'data' => $reports,
        ]);
    }

    /**
     * Generate report
     */
    public function generate(Request $request, Store $store): JsonResponse
    {
        $this->authorize('viewStatistics', $store);

        $validated = $request->validate([
            'type' => 'required|in:sales,products,customers,inventory,comprehensive',
            'format' => 'required|in:csv,pdf',
            'period' => 'sometimes|in:7days,30days,90days,year,month,week',
        ]);

        try {
            $path = match ($validated['following_type']) {
                'sales' => $this->reportingService->generateSalesCSV($store, $validated['period'] ?? '30days'),
                'products' => $this->reportingService->generateProductsCSV($store, $validated['period'] ?? '30days'),
                'customers' => $this->reportingService->generateCustomersCSV($store, $validated['period'] ?? '30days'),
                'inventory' => $this->reportingService->generateInventoryCSV($store),
                'comprehensive' => $this->reportingService->generatePDFReport($store, $validated['period'] ?? '30days'),
            };

            $downloadUrl = Storage::disk('local')->url($path);

            return response()->json([
                'data' => [
                    'download_url' => $downloadUrl,
                    'filename' => basename($path),
                    'type' => $validated['following_type'],
                    'format' => $validated['format'],
                ],
                'message' => 'Report generated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate report: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download generated report
     */
    public function download(Request $request, Store $store, string $filename): JsonResponse
    {
        $this->authorize('viewStatistics', $store);

        $path = "reports/{$store->id}/{$filename}";

        if (! Storage::disk('local')->exists($path)) {
            return response()->json([
                'message' => 'Report not found.',
            ], 404);
        }

        return response()->download(
            Storage::disk('local')->path($path),
            $filename
        );
    }
}
