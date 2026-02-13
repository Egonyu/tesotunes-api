<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use App\Services\PerformanceMonitoringService;

class HealthCheckController extends Controller
{
    /**
     * Basic health check endpoint
     */
    public function index()
    {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
            'version' => config('app.version', '1.0.0'),
        ]);
    }
    
    /**
     * Detailed health check
     */
    public function detailed(PerformanceMonitoringService $performanceMonitor)
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            'queue' => $this->checkQueue(),
        ];
        
        $allHealthy = collect($checks)->every(fn($check) => $check['healthy']);
        
        $metrics = $performanceMonitor->getRealTimeMetrics();
        
        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'version' => config('app.version', '1.0.0'),
            'checks' => $checks,
            'metrics' => $metrics,
        ], $allHealthy ? 200 : 503);
    }
    
    /**
     * Check database connection
     */
    private function checkDatabase(): array
    {
        try {
            $startTime = microtime(true);
            DB::connection()->getPdo();
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            
            return [
                'healthy' => true,
                'latency_ms' => $latency,
                'message' => 'Database connected',
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'latency_ms' => null,
                'message' => 'Database connection failed: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Check cache system
     */
    private function checkCache(): array
    {
        try {
            $startTime = microtime(true);
            $testKey = 'health_check_' . time();
            $testValue = 'test';
            
            Cache::put($testKey, $testValue, 10);
            $retrieved = Cache::get($testKey);
            Cache::forget($testKey);
            
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($retrieved === $testValue) {
                return [
                    'healthy' => true,
                    'latency_ms' => $latency,
                    'message' => 'Cache working',
                ];
            }
            
            return [
                'healthy' => false,
                'latency_ms' => $latency,
                'message' => 'Cache read/write failed',
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'latency_ms' => null,
                'message' => 'Cache error: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Check storage system
     */
    private function checkStorage(): array
    {
        try {
            $startTime = microtime(true);
            $testFile = 'health_check_' . time() . '.txt';
            $testContent = 'test';
            
            Storage::disk('local')->put($testFile, $testContent);
            $retrieved = Storage::disk('local')->get($testFile);
            Storage::disk('local')->delete($testFile);
            
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            
            if ($retrieved === $testContent) {
                return [
                    'healthy' => true,
                    'latency_ms' => $latency,
                    'message' => 'Storage working',
                    'disk' => config('filesystems.default'),
                ];
            }
            
            return [
                'healthy' => false,
                'latency_ms' => $latency,
                'message' => 'Storage read/write failed',
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'latency_ms' => null,
                'message' => 'Storage error: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Check queue system
     */
    private function checkQueue(): array
    {
        try {
            $connection = config('queue.default');
            $size = 0;
            
            // Get queue size (implementation depends on driver)
            if ($connection === 'database') {
                $size = DB::table('jobs')->count();
            }
            
            return [
                'healthy' => true,
                'message' => 'Queue working',
                'connection' => $connection,
                'pending_jobs' => $size,
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'message' => 'Queue error: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get system info (admin only)
     */
    public function system()
    {
        // Only accessible by admins
        if (!auth()->check() || !auth()->user()->hasRole(['admin', 'super_admin'])) {
            abort(403);
        }
        
        return response()->json([
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'environment' => app()->environment(),
            'debug_mode' => config('app.debug'),
            'timezone' => config('app.timezone'),
            'locale' => config('app.locale'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'max_upload_size' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'disk_space' => [
                'total' => disk_total_space('/'),
                'free' => disk_free_space('/'),
                'used_percentage' => round((1 - (disk_free_space('/') / disk_total_space('/'))) * 100, 2),
            ],
        ]);
    }
}
