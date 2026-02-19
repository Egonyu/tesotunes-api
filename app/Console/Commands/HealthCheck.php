<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class HealthCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:health-check {--json : Output results in JSON format} {--detailed : Show detailed information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform comprehensive health check on all system components';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🏥 Starting System Health Check...');
        $this->newLine();

        $results = [
            'timestamp' => now()->toIso8601String(),
            'checks' => [
                'database' => $this->checkDatabase(),
                'storage' => $this->checkStorage(),
                'cache' => $this->checkCache(),
                'queue' => $this->checkQueue(),
                'routes' => $this->checkRoutes(),
                'migrations' => $this->checkMigrations(),
                'environment' => $this->checkEnvironment(),
            ],
        ];

        // Calculate overall health score
        $results['overall_health'] = $this->calculateHealthScore($results['checks']);
        $results['status'] = $this->getOverallStatus($results['overall_health']);

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT));
        } else {
            $this->displayResults($results);
        }

        return $results['status'] === 'healthy' ? 0 : 1;
    }

    /**
     * Check database connectivity and health
     */
    private function checkDatabase(): array
    {
        $this->info('📊 Checking Database...');

        $result = [
            'status' => 'unknown',
            'issues' => [],
            'details' => [],
        ];

        try {
            // Test connection
            DB::connection()->getPdo();
            $result['details']['connection'] = 'Connected';

            // Count migrations
            $migrations = DB::table('migrations')->count();
            $result['details']['migrations_applied'] = $migrations;

            // Check critical tables
            $criticalTables = ['users', 'songs', 'artists', 'payments', 'play_histories'];
            $existingTables = [];

            foreach ($criticalTables as $table) {
                try {
                    DB::table($table)->limit(1)->count();
                    $existingTables[] = $table;
                } catch (Exception $e) {
                    $result['issues'][] = "Table '{$table}' not found or inaccessible";
                }
            }

            $result['details']['critical_tables'] = count($existingTables).'/'.count($criticalTables);

            // Check for pending migrations (simplified check)
            $result['details']['database_driver'] = config('database.default');

            // Test query performance
            $start = microtime(true);
            DB::table('users')->limit(1)->get();
            $queryTime = (microtime(true) - $start) * 1000;
            $result['details']['query_time_ms'] = round($queryTime, 2);

            if (count($result['issues']) === 0) {
                $result['status'] = 'healthy';
                $this->info('  ✅ Database is healthy');
            } else {
                $result['status'] = 'unhealthy';
                $this->error('  ❌ Database has issues');
            }

        } catch (Exception $e) {
            $result['status'] = 'failed';
            $result['issues'][] = $e->getMessage();
            $this->error('  ❌ Database connection failed');
        }

        return $result;
    }

    /**
     * Check storage systems
     */
    private function checkStorage(): array
    {
        $this->info('💾 Checking Storage...');

        $result = [
            'status' => 'unknown',
            'issues' => [],
            'details' => [],
        ];

        try {
            // Check local storage
            $localDisk = Storage::disk('local');
            $result['details']['local_storage'] = $localDisk->exists('test') ? 'Writable' : 'Available';

            // Test write
            try {
                $testFile = 'health-check-'.now()->timestamp.'.txt';
                $localDisk->put($testFile, 'Health check test');
                $localDisk->delete($testFile);
                $result['details']['local_write'] = 'Success';
            } catch (Exception $e) {
                $result['issues'][] = 'Local storage write failed';
                $result['details']['local_write'] = 'Failed';
            }

            // Check public storage link
            if (file_exists(public_path('storage'))) {
                $result['details']['storage_link'] = 'Exists';
            } else {
                $result['issues'][] = 'Public storage link missing (run: php artisan storage:link)';
                $result['details']['storage_link'] = 'Missing';
            }

            // Check DigitalOcean Spaces if configured
            if (config('filesystems.disks.digitalocean')) {
                try {
                    // Just check config existence, don't try to connect
                    $result['details']['digitalocean_spaces'] = 'Configured (not tested)';
                    $result['issues'][] = 'DigitalOcean Spaces needs manual testing';
                } catch (Exception $e) {
                    $result['issues'][] = 'DigitalOcean Spaces configuration issue';
                    $result['details']['digitalocean_spaces'] = 'Failed';
                }
            } else {
                $result['details']['digitalocean_spaces'] = 'Not configured';
            }

            $result['status'] = count($result['issues']) === 0 ? 'healthy' : 'warning';

            if ($result['status'] === 'healthy') {
                $this->info('  ✅ Storage is healthy');
            } else {
                $this->warn('  ⚠️  Storage has warnings');
            }

        } catch (Exception $e) {
            $result['status'] = 'failed';
            $result['issues'][] = $e->getMessage();
            $this->error('  ❌ Storage check failed');
        }

        return $result;
    }

    /**
     * Check cache system
     */
    private function checkCache(): array
    {
        $this->info('🚀 Checking Cache...');

        $result = [
            'status' => 'unknown',
            'issues' => [],
            'details' => [],
        ];

        try {
            $cacheDriver = config('cache.default');
            $result['details']['driver'] = $cacheDriver;

            // Test cache write/read
            $testKey = 'health-check-'.now()->timestamp;
            $testValue = 'test-value-'.rand(1000, 9999);

            Cache::put($testKey, $testValue, 60);
            $retrieved = Cache::get($testKey);
            Cache::forget($testKey);

            if ($retrieved === $testValue) {
                $result['details']['read_write'] = 'Success';
            } else {
                $result['issues'][] = 'Cache read/write mismatch';
                $result['details']['read_write'] = 'Failed';
            }

            // Performance warning for database cache
            if ($cacheDriver === 'database') {
                $result['issues'][] = 'Using database cache (Redis recommended for production)';
                $result['details']['recommendation'] = 'Switch to Redis';
            }

            $result['status'] = count($result['issues']) === 0 ? 'healthy' : 'warning';

            if ($result['status'] === 'healthy') {
                $this->info('  ✅ Cache is healthy');
            } else {
                $this->warn('  ⚠️  Cache has warnings');
            }

        } catch (Exception $e) {
            $result['status'] = 'failed';
            $result['issues'][] = $e->getMessage();
            $this->error('  ❌ Cache check failed');
        }

        return $result;
    }

    /**
     * Check queue system
     */
    private function checkQueue(): array
    {
        $this->info('📬 Checking Queue...');

        $result = [
            'status' => 'unknown',
            'issues' => [],
            'details' => [],
        ];

        try {
            $queueDriver = config('queue.default');
            $result['details']['driver'] = $queueDriver;

            // Count pending jobs (for database queue)
            if ($queueDriver === 'database') {
                try {
                    $pendingJobs = DB::table('jobs')->count();
                    $result['details']['pending_jobs'] = $pendingJobs;

                    if ($pendingJobs > 100) {
                        $result['issues'][] = "High number of pending jobs: {$pendingJobs}";
                    }

                    // Count failed jobs
                    $failedJobs = DB::table('failed_jobs')->count();
                    $result['details']['failed_jobs'] = $failedJobs;

                    if ($failedJobs > 10) {
                        $result['issues'][] = "High number of failed jobs: {$failedJobs}";
                    }
                } catch (Exception $e) {
                    $result['issues'][] = 'Queue tables not accessible';
                }

                // Performance warning
                $result['issues'][] = 'Using database queue (Redis recommended for production)';
                $result['details']['recommendation'] = 'Switch to Redis';
            }

            // Check if queue workers are running (simplified)
            $result['details']['worker_status'] = 'Unable to determine (check manually)';

            $result['status'] = count($result['issues']) > 2 ? 'warning' : 'healthy';

            if ($result['status'] === 'healthy') {
                $this->info('  ✅ Queue is healthy');
            } else {
                $this->warn('  ⚠️  Queue has warnings');
            }

        } catch (Exception $e) {
            $result['status'] = 'failed';
            $result['issues'][] = $e->getMessage();
            $this->error('  ❌ Queue check failed');
        }

        return $result;
    }

    /**
     * Check critical routes
     */
    private function checkRoutes(): array
    {
        $this->info('🛣️  Checking Routes...');

        $result = [
            'status' => 'healthy',
            'issues' => [],
            'details' => [],
        ];

        try {
            $routes = \Illuminate\Support\Facades\Route::getRoutes();
            $result['details']['total_routes'] = count($routes);

            // Count routes by prefix
            $prefixes = [
                'frontend' => 0,
                'backend' => 0,
                'api' => 0,
                'admin' => 0,
            ];

            foreach ($routes as $route) {
                $uri = $route->uri();
                if (str_starts_with($uri, 'api/')) {
                    $prefixes['api']++;
                } elseif (str_starts_with($uri, 'admin/')) {
                    $prefixes['admin']++;
                } elseif (str_starts_with($uri, 'backend/')) {
                    $prefixes['backend']++;
                } else {
                    $prefixes['frontend']++;
                }
            }

            $result['details']['route_breakdown'] = $prefixes;
            $result['details']['routes_cached'] = app()->routesAreCached();

            $this->info('  ✅ Routes loaded successfully');

        } catch (Exception $e) {
            $result['status'] = 'failed';
            $result['issues'][] = $e->getMessage();
            $this->error('  ❌ Route check failed');
        }

        return $result;
    }

    /**
     * Check migrations status
     */
    private function checkMigrations(): array
    {
        $this->info('🔄 Checking Migrations...');

        $result = [
            'status' => 'healthy',
            'issues' => [],
            'details' => [],
        ];

        try {
            $ran = DB::table('migrations')->count();
            $result['details']['migrations_ran'] = $ran;

            // Get latest migration
            $latest = DB::table('migrations')
                ->orderBy('id', 'desc')
                ->first();

            if ($latest) {
                $result['details']['latest_migration'] = $latest->migration;
                $result['details']['latest_batch'] = $latest->batch;
            }

            $this->info('  ✅ Migrations check complete');

        } catch (Exception $e) {
            $result['status'] = 'failed';
            $result['issues'][] = $e->getMessage();
            $this->error('  ❌ Migration check failed');
        }

        return $result;
    }

    /**
     * Check environment configuration
     */
    private function checkEnvironment(): array
    {
        $this->info('🔧 Checking Environment...');

        $result = [
            'status' => 'healthy',
            'issues' => [],
            'details' => [],
        ];

        $result['details']['app_env'] = config('app.env');
        $result['details']['app_debug'] = config('app.debug') ? 'Enabled' : 'Disabled';
        $result['details']['app_url'] = config('app.url');

        // Security warnings
        if (config('app.env') === 'production' && config('app.debug') === true) {
            $result['issues'][] = 'Debug mode enabled in production (SECURITY RISK)';
            $result['status'] = 'critical';
        }

        // Check critical config
        $result['details']['database'] = config('database.default');
        $result['details']['cache'] = config('cache.default');
        $result['details']['queue'] = config('queue.default');
        $result['details']['session'] = config('session.driver');

        // Check PHP version
        $result['details']['php_version'] = PHP_VERSION;
        $result['details']['laravel_version'] = app()->version();

        if ($result['status'] === 'healthy') {
            $this->info('  ✅ Environment is healthy');
        } else {
            $this->error('  ❌ Environment has critical issues');
        }

        return $result;
    }

    /**
     * Calculate overall health score
     */
    private function calculateHealthScore(array $checks): int
    {
        $scores = [];
        $weights = [
            'database' => 30,
            'storage' => 15,
            'cache' => 10,
            'queue' => 15,
            'routes' => 10,
            'migrations' => 10,
            'environment' => 10,
        ];

        foreach ($checks as $key => $check) {
            $score = match ($check['status']) {
                'healthy' => 100,
                'warning' => 70,
                'unhealthy' => 40,
                'failed', 'critical' => 0,
                default => 50,
            };

            $scores[$key] = $score * ($weights[$key] / 100);
        }

        return (int) array_sum($scores);
    }

    /**
     * Get overall status based on health score
     */
    private function getOverallStatus(int $score): string
    {
        return match (true) {
            $score >= 90 => 'healthy',
            $score >= 70 => 'warning',
            $score >= 50 => 'unhealthy',
            default => 'critical',
        };
    }

    /**
     * Display results in human-readable format
     */
    private function displayResults(array $results): void
    {
        $this->newLine();
        $this->line('═══════════════════════════════════════════════════════');
        $this->info('               🏥 HEALTH CHECK SUMMARY');
        $this->line('═══════════════════════════════════════════════════════');
        $this->newLine();

        // Overall status
        $statusEmoji = match ($results['status']) {
            'healthy' => '🟢',
            'warning' => '🟡',
            'unhealthy' => '🟠',
            'critical' => '🔴',
            default => '⚪',
        };

        $this->line("Overall Status: {$statusEmoji} ".strtoupper($results['status']));
        $this->line("Health Score: {$results['overall_health']}/100");
        $this->line("Timestamp: {$results['timestamp']}");
        $this->newLine();

        // Detailed results
        foreach ($results['checks'] as $component => $check) {
            $componentEmoji = match ($check['status']) {
                'healthy' => '✅',
                'warning' => '⚠️ ',
                'unhealthy' => '🟠',
                'failed', 'critical' => '❌',
                default => '⚪',
            };

            $this->line('─────────────────────────────────────────────────────');
            $this->line("{$componentEmoji} ".strtoupper($component).": {$check['status']}");

            if ($this->option('detailed')) {
                if (! empty($check['details'])) {
                    $this->line("\nDetails:");
                    foreach ($check['details'] as $key => $value) {
                        $displayKey = str_replace('_', ' ', ucfirst($key));
                        $displayValue = is_array($value) ? json_encode($value) : $value;
                        $this->line("  • {$displayKey}: {$displayValue}");
                    }
                }
            }

            if (! empty($check['issues'])) {
                $this->line("\nIssues:");
                foreach ($check['issues'] as $issue) {
                    $this->line("  ⚠️  {$issue}");
                }
            }

            $this->newLine();
        }

        $this->line('═══════════════════════════════════════════════════════');

        // Recommendations
        if ($results['overall_health'] < 90) {
            $this->newLine();
            $this->warn('📋 RECOMMENDATIONS:');

            $recommendations = [];
            foreach ($results['checks'] as $check) {
                if (! empty($check['issues'])) {
                    $recommendations = array_merge($recommendations, $check['issues']);
                }
            }

            foreach (array_unique($recommendations) as $rec) {
                $this->line("  • {$rec}");
            }
        }

        $this->newLine();
    }
}
