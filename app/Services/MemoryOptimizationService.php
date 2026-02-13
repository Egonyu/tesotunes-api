<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Memory Optimization Service
 * 
 * Monitors and optimizes memory usage throughout the application
 * Provides memory profiling and leak detection
 */
class MemoryOptimizationService
{
    private array $memorySnapshots = [];
    private float $startMemory;
    private int $peakMemory = 0;
    
    public function __construct()
    {
        $this->startMemory = memory_get_usage(true);
    }
    
    /**
     * Take a memory snapshot at a specific point
     */
    public function snapshot(string $label): void
    {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        
        $this->memorySnapshots[$label] = [
            'current' => $current,
            'peak' => $peak,
            'delta' => $current - $this->startMemory,
            'timestamp' => microtime(true),
        ];
        
        if ($peak > $this->peakMemory) {
            $this->peakMemory = $peak;
        }
    }
    
    /**
     * Get all memory snapshots
     */
    public function getSnapshots(): array
    {
        return $this->memorySnapshots;
    }
    
    /**
     * Get current memory usage report
     */
    public function getMemoryReport(): array
    {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = $this->getMemoryLimit();
        
        return [
            'current_mb' => round($current / 1024 / 1024, 2),
            'peak_mb' => round($peak / 1024 / 1024, 2),
            'limit_mb' => $limit,
            'usage_percent' => $limit > 0 ? round(($peak / ($limit * 1024 * 1024)) * 100, 2) : 0,
            'available_mb' => $limit > 0 ? round($limit - ($peak / 1024 / 1024), 2) : 'unlimited',
            'snapshots' => $this->formatSnapshots(),
        ];
    }
    
    /**
     * Format snapshots for human readability
     */
    private function formatSnapshots(): array
    {
        $formatted = [];
        
        foreach ($this->memorySnapshots as $label => $data) {
            $formatted[$label] = [
                'current_mb' => round($data['current'] / 1024 / 1024, 2),
                'peak_mb' => round($data['peak'] / 1024 / 1024, 2),
                'delta_mb' => round($data['delta'] / 1024 / 1024, 2),
            ];
        }
        
        return $formatted;
    }
    
    /**
     * Get memory limit in MB
     */
    private function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        
        if ($limit === '-1') {
            return 0; // Unlimited
        }
        
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $limit = (int) $limit;
        
        switch ($last) {
            case 'g':
                $limit *= 1024;
                // Fall through
            case 'm':
                return $limit;
            case 'k':
                return $limit / 1024;
            default:
                return $limit / 1024 / 1024;
        }
    }
    
    /**
     * Detect potential memory leaks
     */
    public function detectMemoryLeaks(): array
    {
        $leaks = [];
        $snapshots = array_values($this->memorySnapshots);
        
        for ($i = 1; $i < count($snapshots); $i++) {
            $prev = $snapshots[$i - 1];
            $current = $snapshots[$i];
            
            $deltaSize = $current['current'] - $prev['current'];
            $deltaTime = $current['timestamp'] - $prev['timestamp'];
            
            // Leak indicator: > 10MB increase in < 1 second
            if ($deltaSize > (10 * 1024 * 1024) && $deltaTime < 1.0) {
                $leaks[] = [
                    'from' => array_keys($this->memorySnapshots)[$i - 1],
                    'to' => array_keys($this->memorySnapshots)[$i],
                    'increase_mb' => round($deltaSize / 1024 / 1024, 2),
                    'duration_ms' => round($deltaTime * 1000, 2),
                    'severity' => $deltaSize > (50 * 1024 * 1024) ? 'critical' : 'warning',
                ];
            }
        }
        
        return $leaks;
    }
    
    /**
     * Force garbage collection
     */
    public function forceGarbageCollection(): array
    {
        $beforeCycles = gc_collect_cycles();
        $beforeMemory = memory_get_usage(true);
        
        gc_collect_cycles();
        
        $afterMemory = memory_get_usage(true);
        $freed = $beforeMemory - $afterMemory;
        
        return [
            'cycles_collected' => $beforeCycles,
            'memory_freed_mb' => round($freed / 1024 / 1024, 2),
            'before_mb' => round($beforeMemory / 1024 / 1024, 2),
            'after_mb' => round($afterMemory / 1024 / 1024, 2),
        ];
    }
    
    /**
     * Optimize large collection processing
     */
    public function processInChunks(
        callable $query,
        callable $processor,
        int $chunkSize = 100
    ): array {
        $this->snapshot('chunk_start');
        
        $processed = 0;
        $errors = 0;
        
        $query()->chunk($chunkSize, function ($items) use ($processor, &$processed, &$errors) {
            try {
                $processor($items);
                $processed += $items->count();
                
                // Clear Eloquent model cache
                $items->each->unsetRelations();
                
                // Force GC every 10 chunks
                if ($processed % ($chunkSize * 10) === 0) {
                    gc_collect_cycles();
                    $this->snapshot("chunk_{$processed}");
                }
                
            } catch (\Exception $e) {
                $errors++;
                Log::error('Chunk processing error', [
                    'error' => $e->getMessage(),
                    'chunk_size' => $items->count()
                ]);
            }
        });
        
        $this->snapshot('chunk_end');
        
        return [
            'processed' => $processed,
            'errors' => $errors,
            'chunk_size' => $chunkSize,
            'memory_report' => $this->getMemoryReport(),
        ];
    }
    
    /**
     * Clear all caches to free memory
     */
    public function clearCachesForMemory(): array
    {
        $beforeMemory = memory_get_usage(true);
        
        try {
            // Clear various caches
            Cache::flush();
            
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
            
            gc_collect_cycles();
            
            $afterMemory = memory_get_usage(true);
            $freed = $beforeMemory - $afterMemory;
            
            return [
                'success' => true,
                'memory_freed_mb' => round($freed / 1024 / 1024, 2),
            ];
            
        } catch (\Exception $e) {
            Log::error('Cache clearing failed', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get memory optimization recommendations
     */
    public function getOptimizationRecommendations(): array
    {
        $recommendations = [];
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = $this->getMemoryLimit();
        
        $peakMB = $peak / 1024 / 1024;
        $usagePercent = $limit > 0 ? ($peakMB / $limit) * 100 : 0;
        
        // High memory usage
        if ($usagePercent > 80) {
            $recommendations[] = [
                'severity' => 'critical',
                'issue' => 'Memory usage is above 80%',
                'recommendation' => 'Increase memory_limit in php.ini or optimize query chunk sizes',
            ];
        } elseif ($usagePercent > 60) {
            $recommendations[] = [
                'severity' => 'warning',
                'issue' => 'Memory usage is above 60%',
                'recommendation' => 'Monitor memory usage and consider optimizations',
            ];
        }
        
        // Check for large memory deltas
        $leaks = $this->detectMemoryLeaks();
        if (!empty($leaks)) {
            $recommendations[] = [
                'severity' => 'warning',
                'issue' => count($leaks) . ' potential memory leaks detected',
                'recommendation' => 'Review code sections with rapid memory growth',
                'details' => $leaks,
            ];
        }
        
        // Garbage collection recommendations
        if (gc_enabled() === false) {
            $recommendations[] = [
                'severity' => 'info',
                'issue' => 'Garbage collection is disabled',
                'recommendation' => 'Enable garbage collection with gc_enable()',
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Log memory report to application logs
     */
    public function logMemoryReport(string $context = 'general'): void
    {
        $report = $this->getMemoryReport();
        $leaks = $this->detectMemoryLeaks();
        
        Log::info('Memory usage report', [
            'context' => $context,
            'report' => $report,
            'leaks' => $leaks,
        ]);
    }
}
