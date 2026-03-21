<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure Store module is enabled for tests
        config(['store.enabled' => true]);

        // Disable Vite for tests to avoid manifest errors
        config(['app.asset_url' => null]);
        $this->withoutVite();

        // TEMPORARY: Log test database information for debugging flaky DB issues
        // TODO: Remove after test stability is confirmed (added in fix/tests/mysql-stability)
        $this->logTestDatabaseInfo();
    }

    /**
     * Log database connection information for test diagnostics.
     * Helps debug flaky RefreshDatabase behavior with inconsistent DB names.
     */
    private function logTestDatabaseInfo(): void
    {
        static $logged = false;

        // Only log once per test run to avoid cluttering logs
        if (! $logged) {
            $connection = DB::getDefaultConnection();
            $database = DB::getDatabaseName();
            $envDb = env('DB_DATABASE');
            $configDb = config('database.connections.mysql.database');

            $message = sprintf(
                'TEST DB INFO — Connection: %s | Database: %s | ENV DB_DATABASE: %s | Config DB: %s',
                $connection,
                $database,
                $envDb,
                $configDb
            );

            Log::info($message);

            // Also write to stderr for immediate visibility in test output
            if (defined('STDERR')) {
                fwrite(STDERR, "\n✓ ".$message."\n");
            }

            $logged = true;
        }
    }
}
