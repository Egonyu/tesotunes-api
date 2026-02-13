#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "🗂️  Empty Table Safety Analysis\n";
echo str_repeat("=", 70) . "\n\n";

// Core Laravel tables that should be kept
$coreTables = [
    'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs',
    'migrations', 'password_reset_tokens', 'password_resets',
    'personal_access_tokens', 'sessions'
];

// Get empty tables
$tables = DB::select('SHOW TABLES');
$emptyTables = [];
foreach ($tables as $table) {
    $name = array_values((array)$table)[0];
    if (DB::table($name)->count() === 0 && !in_array($name, $coreTables)) {
        $emptyTables[] = $name;
    }
}

echo "Analyzing " . count($emptyTables) . " empty non-core tables...\n\n";

// Categorize by safety level
$lowRisk = [];
$mediumRisk = [];
$highRisk = [];
$keepForFeatures = [];

foreach ($emptyTables as $table) {
    // Check if pivot, temporary, old, backup
    if (preg_match('/(pivot|temporary|old|backup|test|tmp)$/i', $table)) {
        $lowRisk[] = $table;
    }
    // Check if has corresponding model
    else {
        $modelName = str_replace('_', '', ucwords($table, '_'));
        $modelName = rtrim($modelName, 's');
        
        if (file_exists(__DIR__ . "/../app/Models/{$modelName}.php")) {
            $keepForFeatures[] = $table;
        }
        // Check for cache-like tables
        elseif (preg_match('/(cache|session|token|log)/i', $table)) {
            $highRisk[] = $table;
        }
        else {
            $mediumRisk[] = $table;
        }
    }
}

echo "📊 Safety Categories:\n";
echo str_repeat("-", 70) . "\n\n";

echo "✅ LOW RISK - Safe to remove (" . count($lowRisk) . " tables):\n";
echo "   These are utility tables with no active features\n\n";
foreach ($lowRisk as $t) {
    echo "   - $t\n";
}

echo "\n⚠️  MEDIUM RISK - Review needed (" . count($mediumRisk) . " tables):\n";
echo "   No model found, but may be used\n\n";
foreach (array_slice($mediumRisk, 0, 15) as $t) {
    echo "   - $t\n";
}
if (count($mediumRisk) > 15) {
    echo "   ... and " . (count($mediumRisk) - 15) . " more\n";
}

echo "\n🛡️  HIGH RISK - Keep (system tables) (" . count($highRisk) . " tables):\n";
echo "   Cache/session/token tables\n\n";
foreach ($highRisk as $t) {
    echo "   - $t\n";
}

echo "\n🎯 KEEP FOR FEATURES (" . count($keepForFeatures) . " tables):\n";
echo "   Have models - will be populated when features launch\n\n";
foreach (array_slice($keepForFeatures, 0, 20) as $t) {
    echo "   - $t\n";
}
if (count($keepForFeatures) > 20) {
    echo "   ... and " . (count($keepForFeatures) - 20) . " more\n";
}

echo "\n\n💡 Recommendations:\n";
echo str_repeat("-", 70) . "\n";
echo "1. LOW RISK tables: Safe to remove (create migration)\n";
echo "2. MEDIUM RISK tables: Check git history and code references\n";
echo "3. HIGH RISK tables: Keep (system functionality)\n";
echo "4. KEEP FOR FEATURES: Keep (active models exist)\n";

echo "\n✅ Analysis complete!\n";
