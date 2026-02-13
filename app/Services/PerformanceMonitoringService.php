<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PerformanceMonitoringService
{
    /**
     * Track page load time
     */
    public function trackPageLoad(string $page, float $loadTime): void
    {
        try {
            $key = "performance:page:{$page}:" . now()->format('Y-m-d-H');
            
            $data = Cache::get($key, [
                'count' => 0,
                'total_time' => 0,
                'min_time' => PHP_FLOAT_MAX,
                'max_time' => 0,
            ]);
            
            $data['count']++;
            $data['total_time'] += $loadTime;
            $data['min_time'] = min($data['min_time'], $loadTime);
            $data['max_time'] = max($data['max_time'], $loadTime);
            $data['avg_time'] = $data['total_time'] / $data['count'];
            
            Cache::put($key, $data, 3600); // 1 hour
            
            // Alert if page is slow
            if ($loadTime > 3000) { // 3 seconds
                Log::warning("Slow page load detected", [
                    'page' => $page,
                    'load_time' => $loadTime,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Performance tracking failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Track API response time
     */
    public function trackApiCall(string $endpoint, float $responseTime, int $statusCode): void
    {
        try {
            $key = "performance:api:{$endpoint}:" . now()->format('Y-m-d-H');
            
            $data = Cache::get($key, [
                'count' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'total_time' => 0,
                'min_time' => PHP_FLOAT_MAX,
                'max_time' => 0,
            ]);
            
            $data['count']++;
            $data['total_time'] += $responseTime;
            $data['min_time'] = min($data['min_time'], $responseTime);
            $data['max_time'] = max($data['max_time'], $responseTime);
            $data['avg_time'] = $data['total_time'] / $data['count'];
            
            if ($statusCode >= 200 && $statusCode < 400) {
                $data['success_count']++;
            } else {
                $data['error_count']++;
            }
            
            Cache::put($key, $data, 3600);
            
            // Alert if API is slow
            if ($responseTime > 1000) { // 1 second
                Log::warning("Slow API call detected", [
                    'endpoint' => $endpoint,
                    'response_time' => $responseTime,
                    'status_code' => $statusCode,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('API tracking failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Track database query performance
     */
    public function trackQuery(string $query, float $time): void
    {
        try {
            // Only track slow queries
            if ($time > 100) { // 100ms
                Log::warning("Slow query detected", [
                    'query' => substr($query, 0, 200),
                    'time' => $time,
                ]);
                
                $key = "performance:slow_queries:" . now()->format('Y-m-d');
                $queries = Cache::get($key, []);
                $queries[] = [
                    'query' => substr($query, 0, 200),
                    'time' => $time,
                    'timestamp' => now()->toDateTimeString(),
                ];
                
                // Keep only last 100 slow queries
                if (count($queries) > 100) {
                    $queries = array_slice($queries, -100);
                }
                
                Cache::put($key, $queries, 86400); // 24 hours
            }
        } catch (\Exception $e) {
            Log::error('Query tracking failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Track cache hit/miss rate
     */
    public function trackCacheOperation(string $key, bool $hit): void
    {
        try {
            $statsKey = "performance:cache:stats:" . now()->format('Y-m-d-H');
            
            $stats = Cache::get($statsKey, [
                'hits' => 0,
                'misses' => 0,
            ]);
            
            if ($hit) {
                $stats['hits']++;
            } else {
                $stats['misses']++;
            }
            
            $total = $stats['hits'] + $stats['misses'];
            $stats['hit_rate'] = $total > 0 ? ($stats['hits'] / $total) * 100 : 0;
            
            Cache::put($statsKey, $stats, 3600);
        } catch (\Exception $e) {
            Log::error('Cache tracking failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get performance summary
     */
    public function getPerformanceSummary(string $date = null): array
    {
        $date = $date ?? now()->format('Y-m-d');
        
        $summary = [
            'date' => $date,
            'pages' => [],
            'apis' => [],
            'slow_queries' => [],
            'cache_stats' => [],
        ];
        
        // Get page performance
        $pattern = "performance:page:*:{$date}*";
        $keys = Cache::get($pattern, []);
        foreach ($keys as $key) {
            $summary['pages'][] = Cache::get($key);
        }
        
        // Get API performance
        $pattern = "performance:api:*:{$date}*";
        $keys = Cache::get($pattern, []);
        foreach ($keys as $key) {
            $summary['apis'][] = Cache::get($key);
        }
        
        // Get slow queries
        $summary['slow_queries'] = Cache::get("performance:slow_queries:{$date}", []);
        
        // Get cache stats
        $pattern = "performance:cache:stats:{$date}*";
        $keys = Cache::get($pattern, []);
        foreach ($keys as $key) {
            $summary['cache_stats'][] = Cache::get($key);
        }
        
        return $summary;
    }
    
    /**
     * Track user event (for analytics)
     */
    public function trackEvent(string $event, array $properties = []): void
    {
        try {
            $key = "analytics:events:" . now()->format('Y-m-d');
            
            $events = Cache::get($key, []);
            $events[] = [
                'event' => $event,
                'properties' => $properties,
                'timestamp' => now()->toDateTimeString(),
                'user_id' => auth()->id(),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ];
            
            // Keep only last 1000 events per day
            if (count($events) > 1000) {
                $events = array_slice($events, -1000);
            }
            
            Cache::put($key, $events, 86400);
        } catch (\Exception $e) {
            Log::error('Event tracking failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get real-time performance metrics
     */
    public function getRealTimeMetrics(): array
    {
        $currentHour = now()->format('Y-m-d-H');
        
        return [
            'avg_page_load' => $this->getAverageMetric("performance:page:*:{$currentHour}", 'avg_time'),
            'avg_api_response' => $this->getAverageMetric("performance:api:*:{$currentHour}", 'avg_time'),
            'cache_hit_rate' => $this->getCacheHitRate($currentHour),
            'slow_queries_count' => count(Cache::get("performance:slow_queries:" . now()->format('Y-m-d'), [])),
            'active_users' => $this->getActiveUsersCount(),
        ];
    }
    
    /**
     * Helper: Get average metric
     */
    private function getAverageMetric(string $pattern, string $field): float
    {
        $keys = Cache::get($pattern, []);
        $total = 0;
        $count = 0;
        
        foreach ($keys as $key) {
            $data = Cache::get($key);
            if ($data && isset($data[$field])) {
                $total += $data[$field];
                $count++;
            }
        }
        
        return $count > 0 ? round($total / $count, 2) : 0;
    }
    
    /**
     * Helper: Get cache hit rate
     */
    private function getCacheHitRate(string $hour): float
    {
        $stats = Cache::get("performance:cache:stats:{$hour}");
        return $stats['hit_rate'] ?? 0;
    }
    
    /**
     * Helper: Get active users count
     */
    private function getActiveUsersCount(): int
    {
        // Count unique users in last hour
        $key = "active:users:" . now()->format('Y-m-d-H');
        return count(Cache::get($key, []));
    }
}
