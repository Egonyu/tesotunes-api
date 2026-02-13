<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Query Optimization Service
 * 
 * Provides query optimization utilities, slow query detection,
 * and database performance monitoring
 */
class QueryOptimizationService
{
    private array $slowQueries = [];
    private float $slowQueryThreshold = 100.0; // milliseconds
    
    /**
     * Enable query logging for performance analysis
     */
    public function enableQueryLogging(): void
    {
        DB::enableQueryLog();
        
        // Log slow queries
        DB::listen(function ($query) {
            if ($query->time > $this->slowQueryThreshold) {
                $this->slowQueries[] = [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time' => $query->time,
                    'timestamp' => now()->toISOString(),
                ];
                
                Log::warning('Slow query detected', [
                    'sql' => $query->sql,
                    'time' => $query->time . 'ms',
                    'bindings' => $query->bindings,
                ]);
            }
        });
    }
    
    /**
     * Get slow queries detected during request
     */
    public function getSlowQueries(): array
    {
        return $this->slowQueries;
    }
    
    /**
     * Get query log
     */
    public function getQueryLog(): array
    {
        return DB::getQueryLog();
    }
    
    /**
     * Analyze query performance
     */
    public function analyzeQueryPerformance(): array
    {
        $queries = DB::getQueryLog();
        
        if (empty($queries)) {
            return [
                'enabled' => false,
                'message' => 'Query logging not enabled'
            ];
        }
        
        $totalQueries = count($queries);
        $totalTime = array_sum(array_column($queries, 'time'));
        $avgTime = $totalTime / $totalQueries;
        
        $slowQueries = array_filter($queries, fn($q) => $q['time'] > $this->slowQueryThreshold);
        
        return [
            'enabled' => true,
            'total_queries' => $totalQueries,
            'total_time_ms' => round($totalTime, 2),
            'avg_time_ms' => round($avgTime, 2),
            'slow_queries_count' => count($slowQueries),
            'slow_query_threshold_ms' => $this->slowQueryThreshold,
            'slowest_query' => $this->getSlowestQuery($queries),
            'duplicate_queries' => $this->findDuplicateQueries($queries),
        ];
    }
    
    /**
     * Find the slowest query
     */
    private function getSlowestQuery(array $queries): ?array
    {
        if (empty($queries)) {
            return null;
        }
        
        $slowest = array_reduce($queries, function ($carry, $query) {
            return (!$carry || $query['time'] > $carry['time']) ? $query : $carry;
        });
        
        return [
            'sql' => $slowest['sql'],
            'time_ms' => $slowest['time'],
            'bindings' => $slowest['bindings'],
        ];
    }
    
    /**
     * Find duplicate queries (N+1 indicators)
     */
    private function findDuplicateQueries(array $queries): array
    {
        $querySignatures = [];
        $duplicates = [];
        
        foreach ($queries as $query) {
            $signature = $query['sql'];
            
            if (!isset($querySignatures[$signature])) {
                $querySignatures[$signature] = 0;
            }
            
            $querySignatures[$signature]++;
        }
        
        foreach ($querySignatures as $signature => $count) {
            if ($count > 5) { // More than 5 identical queries suggests N+1
                $duplicates[] = [
                    'sql' => $signature,
                    'count' => $count,
                ];
            }
        }
        
        return $duplicates;
    }
    
    /**
     * Get database statistics
     */
    public function getDatabaseStats(): array
    {
        $driver = config('database.default');
        $connection = DB::connection();
        
        $stats = [
            'driver' => $driver,
            'database' => $connection->getDatabaseName(),
        ];
        
        try {
            if ($driver === 'mysql') {
                $stats = array_merge($stats, $this->getMySQLStats());
            } elseif ($driver === 'sqlite') {
                $stats = array_merge($stats, $this->getSQLiteStats());
            }
        } catch (\Exception $e) {
            $stats['error'] = $e->getMessage();
        }
        
        return $stats;
    }
    
    /**
     * Get MySQL-specific statistics
     */
    private function getMySQLStats(): array
    {
        $stats = [];
        
        // Table sizes
        $tables = DB::select("
            SELECT 
                table_name,
                table_rows,
                ROUND(data_length / 1024 / 1024, 2) as data_size_mb,
                ROUND(index_length / 1024 / 1024, 2) as index_size_mb
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            ORDER BY (data_length + index_length) DESC
            LIMIT 10
        ");
        
        $stats['largest_tables'] = $tables;
        
        // Connection stats
        $connections = DB::selectOne("SHOW STATUS LIKE 'Threads_connected'");
        $stats['active_connections'] = $connections->Value ?? 'unknown';
        
        return $stats;
    }
    
    /**
     * Get SQLite-specific statistics
     */
    private function getSQLiteStats(): array
    {
        $stats = [];
        
        // Database size
        $dbPath = database_path(config('database.connections.sqlite.database'));
        if (file_exists($dbPath)) {
            $stats['database_size_mb'] = round(filesize($dbPath) / 1024 / 1024, 2);
        }
        
        // Table counts
        $tables = DB::select("
            SELECT name, 
                   (SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=m.name) as count
            FROM sqlite_master m 
            WHERE type='table' AND name NOT LIKE 'sqlite_%'
        ");
        
        $stats['tables'] = $tables;
        
        return $stats;
    }
    
    /**
     * Optimize database tables (MySQL only)
     */
    public function optimizeTables(): array
    {
        $driver = config('database.default');
        
        if ($driver !== 'mysql') {
            return [
                'success' => false,
                'message' => 'Table optimization only supported for MySQL'
            ];
        }
        
        try {
            $tables = DB::select("
                SELECT table_name 
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
            ");
            
            $results = [];
            
            foreach ($tables as $table) {
                $tableName = $table->table_name;
                DB::statement("OPTIMIZE TABLE `{$tableName}`");
                $results[] = $tableName;
            }
            
            Log::info('Database tables optimized', ['tables' => $results]);
            
            return [
                'success' => true,
                'tables_optimized' => count($results),
                'tables' => $results,
            ];
            
        } catch (\Exception $e) {
            Log::error('Table optimization failed', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Check for missing indexes
     */
    public function checkMissingIndexes(): array
    {
        $driver = config('database.default');
        
        if ($driver !== 'mysql') {
            return [
                'supported' => false,
                'message' => 'Index analysis only supported for MySQL'
            ];
        }
        
        try {
            // Get tables without indexes on foreign keys
            $results = DB::select("
                SELECT 
                    DISTINCT
                    TABLE_NAME,
                    COLUMN_NAME
                FROM information_schema.COLUMNS
                WHERE 
                    TABLE_SCHEMA = DATABASE()
                    AND COLUMN_NAME LIKE '%_id'
                    AND TABLE_NAME NOT IN (
                        SELECT DISTINCT TABLE_NAME
                        FROM information_schema.STATISTICS
                        WHERE 
                            TABLE_SCHEMA = DATABASE()
                            AND COLUMN_NAME = information_schema.COLUMNS.COLUMN_NAME
                    )
                ORDER BY TABLE_NAME, COLUMN_NAME
            ");
            
            return [
                'supported' => true,
                'potential_missing_indexes' => $results,
                'count' => count($results),
            ];
            
        } catch (\Exception $e) {
            return [
                'supported' => true,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get index usage statistics (MySQL only)
     */
    public function getIndexUsageStats(): array
    {
        $driver = config('database.default');
        
        if ($driver !== 'mysql') {
            return [
                'supported' => false,
                'message' => 'Index statistics only supported for MySQL'
            ];
        }
        
        try {
            $results = DB::select("
                SELECT 
                    TABLE_NAME,
                    INDEX_NAME,
                    CARDINALITY,
                    SEQ_IN_INDEX,
                    COLUMN_NAME
                FROM information_schema.STATISTICS
                WHERE 
                    TABLE_SCHEMA = DATABASE()
                    AND INDEX_NAME != 'PRIMARY'
                ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
            ");
            
            return [
                'supported' => true,
                'indexes' => $results,
                'total_indexes' => count($results),
            ];
            
        } catch (\Exception $e) {
            return [
                'supported' => true,
                'error' => $e->getMessage(),
            ];
        }
    }
}
