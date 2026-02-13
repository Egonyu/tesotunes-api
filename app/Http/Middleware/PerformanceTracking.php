<?php

namespace App\Http\Middleware;

use App\Services\PerformanceMonitoringService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PerformanceTracking
{
    protected PerformanceMonitoringService $performanceMonitor;

    public function __construct(PerformanceMonitoringService $performanceMonitor)
    {
        $this->performanceMonitor = $performanceMonitor;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        
        $response = $next($request);
        
        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds
        
        // Track performance based on request type
        if ($request->is('api/*')) {
            $this->performanceMonitor->trackApiCall(
                $request->path(),
                $duration,
                $response->getStatusCode()
            );
        } else {
            $this->performanceMonitor->trackPageLoad(
                $request->path(),
                $duration
            );
        }
        
        // Add performance headers for debugging (only in non-production)
        if (!app()->isProduction()) {
            $response->headers->set('X-Response-Time', round($duration, 2) . 'ms');
            $response->headers->set('X-Memory-Usage', round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'MB');
        }
        
        return $response;
    }
}
