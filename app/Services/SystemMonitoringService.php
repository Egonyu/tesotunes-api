<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Exception;

class SystemMonitoringService
{
    /**
     * Get comprehensive system health status
     */
    public function getSystemHealth(): array
    {
        return [
            'overall_score' => $this->calculateOverallScore(),
            'status' => $this->getOverallStatus(),
            'deployment' => $this->getDeploymentInfo(),
            'components' => [
                'database' => $this->getDatabaseHealth(),
                'storage' => $this->getStorageHealth(),
                'cache' => $this->getCacheHealth(),
                'queue' => $this->getQueueHealth(),
                'application' => $this->getApplicationHealth(),
            ],
            'statistics' => $this->getSystemStatistics(),
            'backup' => $this->getBackupStatus(),
            'alerts' => $this->getSystemAlerts(),
            'recommendations' => $this->getRecommendations(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Get backup status information
     */
    public function getBackupStatus(): array
    {
        $backupPath = storage_path('app/backups');
        $lastBackup = null;
        $totalBackups = 0;
        $totalSize = 0;
        $healthy = false;
        
        if (File::exists($backupPath)) {
            $files = glob($backupPath . '/*.{sql,sql.gz,zip}', GLOB_BRACE);
            $totalBackups = count($files);
            
            // Get most recent backup
            $latestTime = 0;
            foreach ($files as $file) {
                $totalSize += filesize($file);
                $mtime = filemtime($file);
                if ($mtime > $latestTime) {
                    $latestTime = $mtime;
                    $lastBackup = date('Y-m-d H:i:s', $mtime);
                }
            }
            
            // Determine health based on schedule
            if ($lastBackup) {
                $daysSince = now()->diffInDays(\Carbon\Carbon::parse($lastBackup));
                $schedule = config('backup.schedule', 'daily');
                $maxDays = match($schedule) {
                    'hourly' => 1,
                    'daily' => 2,
                    'weekly' => 8,
                    'monthly' => 32,
                    default => 2,
                };
                $healthy = $daysSince < $maxDays;
            }
        }
        
        return [
            'total_backups' => $totalBackups,
            'total_size' => $this->formatBytes($totalSize),
            'last_backup' => $lastBackup,
            'auto_enabled' => config('backup.auto_enabled', true),
            'schedule' => config('backup.schedule', 'daily'),
            'retention_days' => config('backup.retention_days', 30),
            'healthy' => $healthy,
        ];
    }

    /**
     * Get deployment/workspace information
     */
    public function getDeploymentInfo(): array
    {
        $gitCommit = 'Unknown';
        $gitBranch = 'Unknown';
        
        try {
            if (file_exists(base_path('.git/HEAD'))) {
                $headContent = file_get_contents(base_path('.git/HEAD'));
                if (preg_match('/ref: refs\/heads\/(.*)/', $headContent, $matches)) {
                    $gitBranch = trim($matches[1]);
                }
                
                $commitFile = base_path('.git/refs/heads/' . $gitBranch);
                if (file_exists($commitFile)) {
                    $gitCommit = substr(trim(file_get_contents($commitFile)), 0, 8);
                }
            }
        } catch (Exception $e) {
            // Git info not available
        }

        return [
            'version' => config('app.version', '1.0.0'),
            'app_name' => config('app.name'),
            'environment' => config('app.env'),
            'deployment_id' => substr(md5(config('app.key')), 0, 36),
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'node_version' => $this->getNodeVersion(),
            'database_version' => $this->getDatabaseVersion(),
            'server_os' => php_uname('s') . ' ' . php_uname('r'),
            'web_server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'timezone' => config('app.timezone'),
            'locale' => config('app.locale'),
            'git_commit' => $gitCommit,
            'git_branch' => $gitBranch,
            'last_migration' => $this->getLastMigration(),
            'uptime' => $this->getSystemUptime(),
        ];
    }

    /**
     * Get system statistics similar to Rocket.Chat
     */
    public function getSystemStatistics(): array
    {
        return [
            'users' => $this->getUserStatistics(),
            'content' => $this->getContentStatistics(),
            'activity' => $this->getActivityStatistics(),
            'storage' => $this->getStorageStatistics(),
        ];
    }

    /**
     * Get user statistics
     */
    private function getUserStatistics(): array
    {
        try {
            $totalUsers = DB::table('users')->count();
            $activeUsers = DB::table('users')->where('last_login_at', '>=', now()->subDays(30))->count();
            $premiumUsers = DB::table('users')->where('is_premium', true)->count();
            $artists = DB::table('artists')->count();
            $newToday = DB::table('users')->whereDate('created_at', today())->count();
            $newThisWeek = DB::table('users')->where('created_at', '>=', now()->subWeek())->count();
            $newThisMonth = DB::table('users')->where('created_at', '>=', now()->subMonth())->count();

            return [
                'total' => $totalUsers,
                'active_30d' => $activeUsers,
                'premium' => $premiumUsers,
                'artists' => $artists,
                'new_today' => $newToday,
                'new_this_week' => $newThisWeek,
                'new_this_month' => $newThisMonth,
                'online' => $this->getOnlineUsersCount(),
            ];
        } catch (Exception $e) {
            return ['total' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get content statistics
     */
    private function getContentStatistics(): array
    {
        try {
            return [
                'songs' => DB::table('songs')->count(),
                'albums' => DB::table('albums')->count(),
                'playlists' => DB::table('playlists')->count(),
                'podcasts' => DB::table('podcasts')->count(),
                'podcast_episodes' => DB::table('podcast_episodes')->count(),
                'genres' => DB::table('genres')->count(),
                'events' => DB::table('events')->count(),
            ];
        } catch (Exception $e) {
            return ['songs' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get activity statistics
     */
    private function getActivityStatistics(): array
    {
        try {
            $totalStreams = DB::table('song_plays')->count();
            $streamsToday = DB::table('song_plays')->whereDate('played_at', today())->count();
            $streamsThisWeek = DB::table('song_plays')->where('played_at', '>=', now()->subWeek())->count();
            
            return [
                'total_streams' => $totalStreams,
                'streams_today' => $streamsToday,
                'streams_this_week' => $streamsThisWeek,
                'total_likes' => DB::table('likes')->count(),
                'total_comments' => DB::table('comments')->count(),
                'total_follows' => DB::table('followers')->count(),
            ];
        } catch (Exception $e) {
            return ['total_streams' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get storage statistics
     */
    private function getStorageStatistics(): array
    {
        try {
            $uploadsPath = storage_path('app/public');
            $totalSize = 0;
            $fileCount = 0;
            
            if (File::exists($uploadsPath)) {
                $files = File::allFiles($uploadsPath);
                $fileCount = count($files);
                foreach ($files as $file) {
                    $totalSize += $file->getSize();
                }
            }

            return [
                'total_files' => $fileCount,
                'total_size' => $this->formatBytes($totalSize),
                'total_size_bytes' => $totalSize,
                'audio_files' => DB::table('songs')->whereNotNull('file_path')->count(),
                'artwork_files' => DB::table('songs')->whereNotNull('cover_art')->count(),
            ];
        } catch (Exception $e) {
            return ['total_files' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get online users count
     */
    private function getOnlineUsersCount(): int
    {
        try {
            // Users active in last 5 minutes
            return DB::table('sessions')
                ->where('last_activity', '>=', now()->subMinutes(5)->timestamp)
                ->distinct('user_id')
                ->count('user_id');
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get Node.js version
     */
    private function getNodeVersion(): string
    {
        try {
            $output = shell_exec('node --version 2>/dev/null');
            return $output ? trim($output) : 'Not installed';
        } catch (Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Get database version
     */
    private function getDatabaseVersion(): string
    {
        try {
            $result = DB::select('SELECT VERSION() as version');
            return $result[0]->version ?? 'Unknown';
        } catch (Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Get last migration info
     */
    private function getLastMigration(): array
    {
        try {
            $lastMigration = DB::table('migrations')->orderBy('id', 'desc')->first();
            return [
                'name' => $lastMigration?->migration ?? 'None',
                'batch' => $lastMigration?->batch ?? 0,
            ];
        } catch (Exception $e) {
            return ['name' => 'Unknown', 'batch' => 0];
        }
    }

    /**
     * Get system uptime
     */
    private function getSystemUptime(): string
    {
        try {
            $uptime = shell_exec('uptime -p 2>/dev/null');
            return $uptime ? trim(str_replace('up ', '', $uptime)) : 'Unknown';
        } catch (Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Get human-readable system logs
     */
    public function getRecentLogs(int $lines = 100): array
    {
        $logFile = storage_path('logs/laravel.log');
        
        if (!File::exists($logFile)) {
            return [];
        }

        $logs = [];
        $file = new \SplFileObject($logFile);
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key() + 1;
        
        $startLine = max(0, $totalLines - $lines);
        $file->seek($startLine);

        while (!$file->eof()) {
            $line = $file->fgets();
            if ($line) {
                $parsedLog = $this->parseLogLine($line);
                if ($parsedLog) {
                    $logs[] = $parsedLog;
                }
            }
        }

        return array_reverse($logs);
    }

    /**
     * Parse log line into structured format
     */
    private function parseLogLine(string $line): ?array
    {
        // Match Laravel log format: [YYYY-MM-DD HH:MM:SS] environment.LEVEL: message
        if (preg_match('/\[(.*?)\]\s+(\w+)\.(\w+):\s+(.*)/', $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'environment' => $matches[2],
                'level' => $matches[3],
                'message' => $matches[4],
                'severity' => $this->getSeverityFromLevel($matches[3]),
                'human_readable' => $this->makeLogHumanReadable($matches[4]),
            ];
        }

        return null;
    }

    /**
     * Make log messages human-readable
     */
    private function makeLogHumanReadable(string $message): string
    {
        $patterns = [
            '/SQLSTATE\[(\w+)\]:\s*(.*)/' => '💾 Database Error: $2',
            '/Call to undefined method/' => '⚠️ Programming Error: Method does not exist',
            '/Class.*not found/' => '⚠️ Missing Code File',
            '/Too few arguments/' => '⚠️ Programming Error: Missing parameters',
            '/Undefined variable/' => '⚠️ Programming Error: Variable not defined',
            '/Division by zero/' => '⚠️ Math Error: Attempted division by zero',
            '/Maximum execution time/' => '⏱️ Timeout: Process took too long',
            '/Allowed memory size.*exhausted/' => '💾 Memory Error: Not enough memory',
            '/Connection refused/' => '🔌 Connection Error: Cannot reach service',
            '/No such file or directory/' => '📁 File Error: File not found',
            '/Permission denied/' => '🔒 Permission Error: Access denied',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $message)) {
                return preg_replace($pattern, $replacement, $message);
            }
        }

        return $message;
    }

    /**
     * Get severity icon from log level
     */
    private function getSeverityFromLevel(string $level): string
    {
        return match (strtoupper($level)) {
            'EMERGENCY', 'ALERT', 'CRITICAL' => 'critical',
            'ERROR' => 'error',
            'WARNING' => 'warning',
            'NOTICE', 'INFO' => 'info',
            default => 'debug',
        };
    }

    /**
     * Get database health metrics
     */
    private function getDatabaseHealth(): array
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $connectionTime = (microtime(true) - $start) * 1000;

            $metrics = [
                'status' => 'healthy',
                'connection_time_ms' => round($connectionTime, 2),
                'driver' => config('database.default'),
                'issues' => [],
                'metrics' => [
                    'users_count' => DB::table('users')->count(),
                    'songs_count' => DB::table('songs')->count(),
                    'active_sessions' => DB::table('sessions')->where('last_activity', '>=', now()->subHour()->timestamp)->count(),
                ],
            ];

            // Check for slow queries
            if ($connectionTime > 100) {
                $metrics['issues'][] = '⏱️ Slow database connection (>' . round($connectionTime) . 'ms)';
                $metrics['status'] = 'warning';
            }

            // Check for large tables
            try {
                $songsCount = DB::table('songs')->count();
                if ($songsCount > 10000) {
                    $metrics['issues'][] = '📊 Large songs table (' . number_format($songsCount) . ' records) - consider archiving';
                }
            } catch (Exception $e) {
                // Table might not exist
            }

            return $metrics;

        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'connection_time_ms' => null,
                'driver' => config('database.default'),
                'issues' => ['❌ Database connection failed: ' . $this->makeLogHumanReadable($e->getMessage())],
                'metrics' => [],
            ];
        }
    }

    /**
     * Get storage health metrics
     */
    private function getStorageHealth(): array
    {
        $issues = [];
        $metrics = [];

        try {
            // Check local storage
            $localPath = storage_path('app');
            $freeSpace = disk_free_space($localPath);
            $totalSpace = disk_total_space($localPath);
            $usedPercentage = (($totalSpace - $freeSpace) / $totalSpace) * 100;

            $metrics['local_storage'] = [
                'free' => $this->formatBytes($freeSpace),
                'total' => $this->formatBytes($totalSpace),
                'used_percentage' => round($usedPercentage, 2),
            ];

            if ($usedPercentage > 90) {
                $issues[] = '💾 Storage almost full (' . round($usedPercentage) . '% used)';
            }

            // Check public storage link
            if (!file_exists(public_path('storage'))) {
                $issues[] = '🔗 Public storage link missing - Run: php artisan storage:link';
            } else {
                $metrics['storage_link'] = 'Connected';
            }

            // Check uploads directory
            $uploadsPath = storage_path('app/public');
            if (File::exists($uploadsPath)) {
                $metrics['uploads_directory'] = 'Exists';
            } else {
                $issues[] = '📁 Uploads directory missing';
            }

            $status = empty($issues) ? 'healthy' : (count($issues) > 2 ? 'failed' : 'warning');

            return compact('status', 'issues', 'metrics');

        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'issues' => ['❌ Storage check failed: ' . $e->getMessage()],
                'metrics' => [],
            ];
        }
    }

    /**
     * Get cache health metrics
     */
    private function getCacheHealth(): array
    {
        try {
            $driver = config('cache.default');
            $issues = [];

            // Test cache write/read
            $testKey = 'health_check_' . now()->timestamp;
            $testValue = 'test_' . rand(1000, 9999);

            Cache::put($testKey, $testValue, 60);
            $retrieved = Cache::get($testKey);
            Cache::forget($testKey);

            $working = $retrieved === $testValue;

            if (!$working) {
                $issues[] = '❌ Cache read/write test failed';
            }

            // Warning for non-optimal drivers
            if ($driver === 'database') {
                $issues[] = '⚠️ Using database for cache - Redis recommended for better performance';
            }

            $status = empty($issues) ? 'healthy' : 'warning';

            return [
                'status' => $status,
                'driver' => $driver,
                'working' => $working,
                'issues' => $issues,
                'metrics' => [
                    'driver' => $driver,
                    'test_passed' => $working,
                ],
            ];

        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'driver' => config('cache.default'),
                'working' => false,
                'issues' => ['❌ Cache system error: ' . $e->getMessage()],
                'metrics' => [],
            ];
        }
    }

    /**
     * Get queue health metrics
     */
    private function getQueueHealth(): array
    {
        try {
            $driver = config('queue.default');
            $issues = [];
            $metrics = ['driver' => $driver];

            if ($driver === 'database') {
                // Count pending jobs
                $pendingJobs = DB::table('jobs')->count();
                $metrics['pending_jobs'] = $pendingJobs;

                // Count failed jobs
                $failedJobs = DB::table('failed_jobs')->count();
                $metrics['failed_jobs'] = $failedJobs;

                if ($pendingJobs > 100) {
                    $issues[] = '⚠️ High number of pending jobs (' . $pendingJobs . ') - Queue workers may be slow or stopped';
                }

                if ($failedJobs > 10) {
                    $issues[] = '❌ Multiple failed jobs detected (' . $failedJobs . ') - Review and retry';
                }

                $issues[] = '💡 Using database queue - Redis recommended for production';
            }

            $status = count($issues) > 2 ? 'warning' : 'healthy';

            return compact('status', 'driver', 'issues', 'metrics');

        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'driver' => config('queue.default'),
                'issues' => ['❌ Queue system error: ' . $e->getMessage()],
                'metrics' => [],
            ];
        }
    }

    /**
     * Get application health metrics
     */
    private function getApplicationHealth(): array
    {
        $issues = [];
        $metrics = [];

        // Environment checks
        $metrics['environment'] = config('app.env');
        $metrics['debug_mode'] = config('app.debug');
        $metrics['php_version'] = PHP_VERSION;
        $metrics['laravel_version'] = app()->version();

        // Security warnings
        if (config('app.env') === 'production' && config('app.debug')) {
            $issues[] = '🔥 CRITICAL: Debug mode enabled in production!';
        }

        // Check migrations
        try {
            $migrationsRan = DB::table('migrations')->count();
            $metrics['migrations_ran'] = $migrationsRan;
        } catch (Exception $e) {
            $issues[] = '❌ Cannot access migrations table';
        }

        // Check routes
        $totalRoutes = count(\Illuminate\Support\Facades\Route::getRoutes());
        $metrics['total_routes'] = $totalRoutes;

        $status = empty($issues) ? 'healthy' : (str_contains(implode(' ', $issues), 'CRITICAL') ? 'critical' : 'warning');

        return compact('status', 'issues', 'metrics');
    }

    /**
     * Get system alerts
     */
    private function getSystemAlerts(): array
    {
        $alerts = [];

        // Critical alerts
        if (config('app.env') === 'production' && config('app.debug')) {
            $alerts[] = [
                'level' => 'critical',
                'title' => 'Security Risk',
                'message' => 'Debug mode is enabled in production environment',
                'action' => 'Disable debug mode immediately',
            ];
        }

        // Check for recent errors
        $logFile = storage_path('logs/laravel.log');
        if (File::exists($logFile)) {
            $recentContent = File::get($logFile);
            $errorCount = substr_count($recentContent, '.ERROR:');
            
            if ($errorCount > 50) {
                $alerts[] = [
                    'level' => 'warning',
                    'title' => 'High Error Rate',
                    'message' => "Detected {$errorCount} errors in recent logs",
                    'action' => 'Review system logs for recurring issues',
                ];
            }
        }

        return $alerts;
    }

    /**
     * Get system recommendations
     */
    private function getRecommendations(): array
    {
        $recommendations = [];

        // Cache recommendations
        if (config('cache.default') === 'database') {
            $recommendations[] = [
                'priority' => 'high',
                'title' => 'Optimize Caching',
                'description' => 'Switch to Redis for significantly better cache performance',
                'impact' => 'High - Improves page load times by 40-60%',
            ];
        }

        // Queue recommendations
        if (config('queue.default') === 'database') {
            $recommendations[] = [
                'priority' => 'medium',
                'title' => 'Optimize Queue Processing',
                'description' => 'Switch to Redis for faster queue job processing',
                'impact' => 'Medium - Improves background job performance',
            ];
        }

        return $recommendations;
    }

    /**
     * Calculate overall health score
     */
    private function calculateOverallScore(): int
    {
        $components = [
            'database' => $this->getDatabaseHealth(),
            'storage' => $this->getStorageHealth(),
            'cache' => $this->getCacheHealth(),
            'queue' => $this->getQueueHealth(),
            'application' => $this->getApplicationHealth(),
        ];

        $weights = [
            'database' => 35,
            'storage' => 20,
            'cache' => 15,
            'queue' => 15,
            'application' => 15,
        ];

        $totalScore = 0;

        foreach ($components as $key => $component) {
            $score = match ($component['status']) {
                'healthy' => 100,
                'warning' => 70,
                'failed', 'critical' => 0,
                default => 50,
            };

            $totalScore += $score * ($weights[$key] / 100);
        }

        return (int) $totalScore;
    }

    /**
     * Get overall status from score
     */
    private function getOverallStatus(): string
    {
        $score = $this->calculateOverallScore();

        return match (true) {
            $score >= 90 => 'healthy',
            $score >= 70 => 'warning',
            $score >= 50 => 'degraded',
            default => 'critical',
        };
    }

    /**
     * Format bytes to human-readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Execute system maintenance command
     */
    public function executeCommand(string $command): array
    {
        try {
            switch ($command) {
                case 'cache:clear':
                    Artisan::call('cache:clear');
                    return [
                        'success' => true,
                        'message' => '✅ Cache cleared successfully',
                        'output' => Artisan::output(),
                    ];

                case 'config:cache':
                    Artisan::call('config:cache');
                    return [
                        'success' => true,
                        'message' => '✅ Configuration cached successfully',
                        'output' => Artisan::output(),
                    ];

                case 'route:cache':
                    Artisan::call('route:cache');
                    return [
                        'success' => true,
                        'message' => '✅ Routes cached successfully',
                        'output' => Artisan::output(),
                    ];

                case 'view:clear':
                    Artisan::call('view:clear');
                    return [
                        'success' => true,
                        'message' => '✅ View cache cleared successfully',
                        'output' => Artisan::output(),
                    ];

                case 'optimize':
                    Artisan::call('optimize');
                    return [
                        'success' => true,
                        'message' => '✅ Application optimized successfully',
                        'output' => Artisan::output(),
                    ];

                case 'optimize:clear':
                    Artisan::call('optimize:clear');
                    return [
                        'success' => true,
                        'message' => '✅ All caches cleared successfully',
                        'output' => Artisan::output(),
                    ];

                case 'queue:restart':
                    Artisan::call('queue:restart');
                    return [
                        'success' => true,
                        'message' => '✅ Queue workers restarted',
                        'output' => Artisan::output(),
                    ];

                default:
                    return [
                        'success' => false,
                        'message' => '❌ Unknown command',
                        'output' => null,
                    ];
            }
        } catch (Exception $e) {
            Log::error('System command execution failed', [
                'command' => $command,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '❌ Command failed: ' . $e->getMessage(),
                'output' => null,
            ];
        }
    }

    /**
     * Run system tests (safe for production)
     */
    public function runHealthTests(): array
    {
        $tests = [];

        // Database connection test
        $tests[] = [
            'name' => 'Database Connection',
            'status' => $this->testDatabaseConnection(),
            'message' => $this->testDatabaseConnection() ? '✅ Connected' : '❌ Failed',
        ];

        // Cache test
        $tests[] = [
            'name' => 'Cache System',
            'status' => $this->testCache(),
            'message' => $this->testCache() ? '✅ Working' : '❌ Failed',
        ];

        // Storage test
        $tests[] = [
            'name' => 'File Storage',
            'status' => $this->testStorage(),
            'message' => $this->testStorage() ? '✅ Writable' : '❌ Failed',
        ];

        // Application test
        $tests[] = [
            'name' => 'Application',
            'status' => true,
            'message' => '✅ Running (v' . app()->version() . ')',
        ];

        return $tests;
    }

    private function testDatabaseConnection(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function testCache(): bool
    {
        try {
            $key = 'test_' . now()->timestamp;
            Cache::put($key, 'value', 1);
            $result = Cache::get($key) === 'value';
            Cache::forget($key);
            return $result;
        } catch (Exception $e) {
            return false;
        }
    }

    private function testStorage(): bool
    {
        try {
            $testFile = 'health_test_' . now()->timestamp . '.txt';
            Storage::put($testFile, 'test');
            $exists = Storage::exists($testFile);
            Storage::delete($testFile);
            return $exists;
        } catch (Exception $e) {
            return false;
        }
    }
}
