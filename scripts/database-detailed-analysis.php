#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "📊 Comprehensive Database Analysis\n";
echo str_repeat("=", 70) . "\n\n";

// Get all tables
$tables = DB::select('SHOW TABLES');
$tableCount = count($tables);
$emptyTables = [];
$smallTables = [];
$activeTables = [];

echo "Analyzing $tableCount tables...\n\n";

foreach ($tables as $table) {
    $tableName = array_values((array)$table)[0];
    $count = DB::table($tableName)->count();
    
    // Get table info
    $tableInfo = DB::select("
        SELECT 
            table_name,
            ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb,
            table_rows
        FROM information_schema.TABLES 
        WHERE table_schema = DATABASE()
        AND table_name = ?
    ", [$tableName])[0] ?? null;
    
    if ($count === 0) {
        $emptyTables[] = $tableName;
    } elseif ($count < 10) {
        $smallTables[] = [
            'name' => $tableName,
            'count' => $count,
            'size' => $tableInfo->size_mb ?? 0
        ];
    } else {
        $activeTables[] = [
            'name' => $tableName,
            'count' => $count,
            'size' => $tableInfo->size_mb ?? 0
        ];
    }
}

// Summary
echo "📊 Summary:\n";
echo str_repeat("-", 70) . "\n";
echo "Total Tables:  $tableCount\n";
echo "Empty Tables:  " . count($emptyTables) . " (" . round(count($emptyTables)/$tableCount*100, 1) . "%)\n";
echo "Small Tables:  " . count($smallTables) . " (< 10 rows)\n";
echo "Active Tables: " . count($activeTables) . " (>= 10 rows)\n\n";

// Empty tables by category
echo "🗂️  Empty Tables by Category:\n";
echo str_repeat("-", 70) . "\n";

$categories = [
    'Core' => ['cache', 'failed_jobs', 'jobs', 'job_batches', 'sessions', 'personal_access_tokens'],
    'Activity' => ['activity', 'activities', 'activity_comments', 'activity_likes', 'activity_logs'],
    'Awards' => ['award', 'awards'],
    'Artist' => ['artist_'],
    'Events' => ['event'],
    'Music' => ['album', 'song', 'playlist', 'play_'],
    'Payments' => ['payment', 'transaction', 'payout'],
    'Store' => ['store_', 'product'],
    'Sacco' => ['sacco_'],
    'Social' => ['comment', 'like', 'follow', 'share'],
    'Moderation' => ['moderation', 'flag', 'report'],
    'Other' => []
];

$categorized = [];
foreach ($emptyTables as $table) {
    $assigned = false;
    foreach ($categories as $category => $patterns) {
        if ($category === 'Other') continue;
        foreach ($patterns as $pattern) {
            if (strpos($table, $pattern) !== false) {
                $categorized[$category][] = $table;
                $assigned = true;
                break 2;
            }
        }
    }
    if (!$assigned) {
        $categorized['Other'][] = $table;
    }
}

foreach ($categorized as $category => $tables) {
    if (count($tables) > 0) {
        echo "\n$category (" . count($tables) . " tables):\n";
        foreach (array_slice($tables, 0, 5) as $table) {
            echo "  - $table\n";
        }
        if (count($tables) > 5) {
            echo "  ... and " . (count($tables) - 5) . " more\n";
        }
    }
}

// Small tables (potentially test data)
echo "\n\n📦 Small Tables (< 10 rows):\n";
echo str_repeat("-", 70) . "\n";
printf("%-40s %10s %10s\n", "Table", "Rows", "Size (MB)");
echo str_repeat("-", 70) . "\n";
usort($smallTables, fn($a, $b) => $b['count'] <=> $a['count']);
foreach (array_slice($smallTables, 0, 20) as $table) {
    printf("%-40s %10d %10.2f\n", $table['name'], $table['count'], $table['size']);
}
if (count($smallTables) > 20) {
    echo "... and " . (count($smallTables) - 20) . " more\n";
}

// Active tables
echo "\n\n✅ Active Tables (>= 10 rows):\n";
echo str_repeat("-", 70) . "\n";
printf("%-40s %10s %10s\n", "Table", "Rows", "Size (MB)");
echo str_repeat("-", 70) . "\n";
usort($activeTables, fn($a, $b) => $b['count'] <=> $a['count']);
foreach ($activeTables as $table) {
    printf("%-40s %10d %10.2f\n", $table['name'], $table['count'], $table['size']);
}

echo "\n\n💡 Recommendations:\n";
echo str_repeat("-", 70) . "\n";
echo "1. Empty cache tables (cache, cache_locks) are normal\n";
echo "2. Empty job tables (jobs, failed_jobs) are normal for low-traffic dev/staging\n";
echo "3. Review empty feature tables - may be unused functionality\n";
echo "4. Small tables may contain test/seed data\n";
echo "5. Consider creating migration to drop truly unused tables\n";

echo "\n✅ Analysis complete!\n";
